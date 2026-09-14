<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Resources\Application\Actions\SaveResource;
use App\Modules\Resources\Application\Actions\SaveResourceType;
use App\Modules\Resources\Application\Actions\SetServiceResourceRequirements;
use App\Modules\Resources\Domain\Data\ResourceInput;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Chairs, rooms and devices, and what each service needs.
 *
 * Validate → call one Action → return a resource. No business logic here: the
 * branch lock, the capacity floor and the audit entry all live in the Actions,
 * because the Livewire screens call the same ones (CLAUDE.md).
 *
 * Reads are scoped to the caller's branches; a resource at a branch they may
 * not work in is NOT FOUND rather than forbidden
 * (docs/08-AUDIT-SECURITY.md).
 */
final class ResourceController extends Controller
{
    public function types(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        $types = ResourceType::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (ResourceType $type): array => [
                'uuid' => $type->uuid,
                'name' => $type->name->get(),
                'description' => $type->description?->get(),
                'is_active' => $type->is_active,
                'sort_order' => $type->sort_order,
                'archived' => $type->isArchived(),
            ])
            ->all();

        return ApiResponse::data(['resource_types' => $types]);
    }

    public function storeType(Request $request, SaveResourceType $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'array'],
            'description' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        /** @var array<string, string|null> $name */
        $name = $validated['name'];
        /** @var array<string, string|null> $description */
        $description = $validated['description'] ?? [];

        $type = $save(
            $name,
            $user,
            null,
            $description,
            (bool) ($validated['is_active'] ?? true),
            (int) ($validated['sort_order'] ?? 0),
        );

        return ApiResponse::data(['uuid' => $type->uuid], 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->authorizeRead($request);

        $query = OperationalResource::query()->with(['type', 'branch', 'department']);

        $user->branchScope()->applyTo($query, 'branch_id');

        $resources = $query
            ->orderBy('branch_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (OperationalResource $resource): array => [
                'uuid' => $resource->uuid,
                'name' => $resource->name->get(),
                'type' => $resource->type === null ? null : [
                    'uuid' => $resource->type->uuid,
                    'name' => $resource->type->name->get(),
                ],
                'branch' => $resource->branch === null ? null : [
                    'uuid' => $resource->branch->uuid,
                    'name' => $resource->branch->name->get(),
                ],
                'department' => $resource->department === null ? null : [
                    'uuid' => $resource->department->uuid,
                    'name' => $resource->department->name->get(),
                ],
                'capacity' => $resource->capacity,
                'is_active' => $resource->is_active,
                'archived' => $resource->isArchived(),
            ])
            ->all();

        return ApiResponse::data(['resources' => $resources]);
    }

    public function store(Request $request, SaveResource $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resource = $save(ResourceInput::fromArray($this->resourcePayload($request)), $user);

        return ApiResponse::data(['uuid' => $resource->uuid], 201);
    }

    public function update(Request $request, string $uuid, SaveResource $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $resource = $save(
            ResourceInput::fromArray($this->resourcePayload($request)),
            $user,
            $this->resource($uuid, $user),
        );

        return ApiResponse::data(['uuid' => $resource->uuid]);
    }

    public function archive(Request $request, string $uuid, SaveResource $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $save->archive($this->resource($uuid, $user), $user);

        return ApiResponse::data(['archived' => true]);
    }

    /**
     * A service's resource requirements, replaced wholesale.
     */
    public function setRequirements(
        Request $request,
        string $uuid,
        SetServiceResourceRequirements $set,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'requirements' => ['present', 'array'],
            'requirements.*.type' => ['required', 'string'],
            'requirements.*.quantity' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        $service = Service::query()->where('uuid', $uuid)->first();

        if (! $service instanceof Service) {
            throw new NotFoundHttpException;
        }

        /** @var array<int, array<string, mixed>> $requirements */
        $requirements = $validated['requirements'];

        $rows = $set($service, $requirements, $user);

        return ApiResponse::data([
            'requirements' => array_map(
                static fn (ServiceResourceRequirement $r): array => [
                    'resource_type_uuid' => $r->type?->uuid,
                    'quantity' => $r->quantity,
                ],
                $rows,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resourcePayload(Request $request): array
    {
        return $request->validate([
            'resource_type' => ['required', 'string'],
            'branch' => ['required', 'string'],
            'department' => ['nullable', 'string'],
            'name' => ['required', 'array'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    private function resource(string $uuid, User $user): OperationalResource
    {
        $query = OperationalResource::query()->where('uuid', $uuid);

        // Scoped, so a resource at a branch this user may not work in is NOT
        // FOUND — never a 403, which would confirm it exists.
        $user->branchScope()->applyTo($query, 'branch_id');

        $resource = $query->first();

        if (! $resource instanceof OperationalResource) {
            throw new NotFoundHttpException;
        }

        return $resource;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeRead(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPermission(Permission::ResourceView)) {
            throw new AuthorizationException('You may not view resources.');
        }

        return $user;
    }
}
