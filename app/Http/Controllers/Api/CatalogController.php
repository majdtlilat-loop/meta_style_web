<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\ArchiveService;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Departments\Application\Actions\SaveDepartment;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Departments, categories and services for signed-in staff.
 *
 * One controller because the three are edited from one screen and share their
 * lookup and presentation helpers. The Actions keep them separate where it
 * matters — permissions, audit and archive semantics all differ.
 */
final class CatalogController extends Controller
{
    // ---------------------------------------------------------------- read

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $this->require($user, Permission::ServiceView);

        $currency = Currency::default();

        $services = Service::query()
            ->with(['variations', 'addons', 'category', 'department', 'media', 'branches', 'eligibleEmployees'])
            ->whereNull('archived_at')
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (Service $s): array => $this->presentService($s, $currency))
            ->values()->all();

        return ApiResponse::data([
            'currency' => $currency->value,
            'departments' => Department::query()->active()->get()
                ->map(fn (Department $d): array => [
                    'uuid' => $d->uuid,
                    'name' => $d->name->all(),
                    'description' => $d->description?->all(),
                    'is_active' => $d->is_active,
                    'sort_order' => $d->sort_order,
                ])->values()->all(),
            'categories' => ServiceCategory::query()->active()->get()
                ->map(fn (ServiceCategory $c): array => [
                    'uuid' => $c->uuid,
                    'name' => $c->name->all(),
                    'description' => $c->description?->all(),
                    'is_active' => $c->is_active,
                    'is_public' => $c->is_public,
                    'sort_order' => $c->sort_order,
                ])->values()->all(),
            'addons' => ServiceAddon::query()->active()->get()
                ->map(fn (ServiceAddon $a): array => [
                    'uuid' => $a->uuid,
                    'name' => $a->name->all(),
                    'price' => $a->price($currency)->toArray(),
                    'duration_minutes' => $a->duration_minutes,
                ])->values()->all(),
            'services' => $services,
        ]);
    }

    // ---------------------------------------------------------- departments

    public function storeDepartment(Request $request, SaveDepartment $save): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate($this->translatableRules() + [
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $department = $save(
            name: $data['name'],
            actingUser: $user,
            description: $data['description'] ?? [],
            isActive: (bool) ($data['is_active'] ?? true),
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );

        return ApiResponse::data(['uuid' => $department->uuid], 201);
    }

    public function archiveDepartment(string $uuid, Request $request, SaveDepartment $save): JsonResponse
    {
        $user = $this->user($request);

        /** @var Department|null $department */
        $department = Department::query()->where('uuid', $uuid)->first();

        $save->archive($department ?? throw new ModelNotFoundException, $user);

        return ApiResponse::data(['uuid' => $uuid, 'archived' => true]);
    }

    // ----------------------------------------------------------- categories

    public function storeCategory(Request $request, SaveServiceCategory $save): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate($this->translatableRules() + [
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $category = $save(
            name: $data['name'],
            actingUser: $user,
            description: $data['description'] ?? [],
            isActive: (bool) ($data['is_active'] ?? true),
            isPublic: (bool) ($data['is_public'] ?? true),
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );

        return ApiResponse::data(['uuid' => $category->uuid], 201);
    }

    // ------------------------------------------------------------- services

    public function storeService(Request $request, SaveService $save): JsonResponse
    {
        $user = $this->user($request);

        $service = $save($this->serviceInput($request), $user);

        return ApiResponse::data($this->presentService($service->load([
            'variations', 'addons', 'category', 'department', 'media', 'branches', 'eligibleEmployees',
        ]), Currency::default()), 201);
    }

    public function updateService(string $uuid, Request $request, SaveService $save): JsonResponse
    {
        $user = $this->user($request);

        $service = $save($this->serviceInput($request), $user, $this->findService($uuid));

        return ApiResponse::data($this->presentService($service->load([
            'variations', 'addons', 'category', 'department', 'media', 'branches', 'eligibleEmployees',
        ]), Currency::default()));
    }

    public function archiveService(string $uuid, Request $request, ArchiveService $archive): JsonResponse
    {
        $user = $this->user($request);

        $service = $archive($this->findService($uuid), $user);

        return ApiResponse::data(['uuid' => $service->uuid, 'archived' => true]);
    }

    // ---------------------------------------------------------------- input

    private function serviceInput(Request $request): ServiceInput
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'short_description' => ['nullable', 'array'],
            'short_description.*' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:5000'],

            'department' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],

            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],

            // Minor units, integer. Never a decimal string on the wire: IQD has
            // no minor unit at all, so "25000.00" would be ambiguous
            // (docs/10-API-FOUNDATION.md §9).
            'price_minor' => ['required', 'integer', 'min:0'],

            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'is_online_bookable' => ['boolean'],
            'available_at_all_branches' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],

            'variations' => ['nullable', 'array'],
            'variations.*.uuid' => ['nullable', 'string'],
            'variations.*.name' => ['required', 'array'],
            'variations.*.price_minor' => ['nullable', 'integer', 'min:0'],
            'variations.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'variations.*.is_active' => ['boolean'],

            'addons' => ['nullable', 'array'],
            'addons.*' => ['string'],
            'branches' => ['nullable', 'array'],
            'branches.*' => ['string'],
            'employees' => ['nullable', 'array'],
            'employees.*' => ['string'],
        ]);

        return new ServiceInput(
            name: $data['name'],
            durationMinutes: (int) $data['duration_minutes'],
            priceMinor: (int) $data['price_minor'],
            departmentId: $this->idFor(Department::query(), $data['department'] ?? null),
            serviceCategoryId: $this->idFor(ServiceCategory::query(), $data['category'] ?? null),
            shortDescription: $data['short_description'] ?? [],
            description: $data['description'] ?? [],
            isActive: (bool) ($data['is_active'] ?? true),
            isPublic: (bool) ($data['is_public'] ?? true),
            isOnlineBookable: (bool) ($data['is_online_bookable'] ?? true),
            availableAtAllBranches: (bool) ($data['available_at_all_branches'] ?? true),
            sortOrder: (int) ($data['sort_order'] ?? 0),
            variations: $data['variations'] ?? null,
            addonIds: $this->idsFor(ServiceAddon::query(), $data['addons'] ?? null),
            branchIds: $this->idsFor(Branch::query(), $data['branches'] ?? null),
            employeeIds: $this->idsFor(Employee::query(), $data['employees'] ?? null),
        );
    }

    /**
     * Translates a client-supplied uuid into an internal id.
     *
     * Clients only ever see uuids (docs/08-AUDIT-SECURITY.md §19), and the
     * lookup runs on the tenant connection — so a uuid from another center
     * finds nothing rather than linking across tenants.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function idFor(Builder $query, ?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = $query->where('uuid', $uuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>|null  $uuids
     * @return list<int>|null
     */
    private function idsFor(Builder $query, ?array $uuids): ?array
    {
        if ($uuids === null) {
            return null;
        }

        /** @var list<int> $ids */
        $ids = $query->whereIn('uuid', $uuids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $ids;
    }

    // ------------------------------------------------------------ presenter

    /**
     * @return array<string, mixed>
     */
    private function presentService(Service $service, Currency $currency): array
    {
        return [
            'uuid' => $service->uuid,
            'name' => $service->name->all(),
            'short_description' => $service->short_description?->all(),
            'description' => $service->description?->all(),
            'duration_minutes' => $service->duration_minutes,
            'price' => $service->price($currency)->toArray(),
            'price_minor' => $service->price_minor,
            'is_active' => $service->is_active,
            'is_public' => $service->is_public,
            'is_online_bookable' => $service->is_online_bookable,
            'available_at_all_branches' => $service->available_at_all_branches,
            'sort_order' => $service->sort_order,
            'archived_at' => $service->archived_at?->toIso8601String(),
            'department' => $service->department?->uuid,
            'category' => $service->category?->uuid,
            'variations' => $service->relationLoaded('variations')
                ? $service->variations->map(fn ($v): array => [
                    'uuid' => $v->uuid,
                    'name' => $v->name->all(),
                    'price_minor' => $v->price_minor,
                    'duration_minutes' => $v->duration_minutes,
                    // What a customer would actually be charged, with the
                    // inheritance resolved (ADR-037).
                    'effective_price' => $v->effectivePrice($service, $currency)->toArray(),
                    'effective_duration_minutes' => $v->effectiveDurationMinutes($service),
                    'is_active' => $v->is_active,
                ])->values()->all()
                : [],
            'addons' => $service->relationLoaded('addons')
                ? $service->addons->pluck('uuid')->values()->all()
                : [],
            'branches' => $service->relationLoaded('branches')
                ? $service->branches->pluck('uuid')->values()->all()
                : [],
            'employees' => $service->relationLoaded('eligibleEmployees')
                ? $service->eligibleEmployees->pluck('uuid')->values()->all()
                : [],
        ];
    }

    // ---------------------------------------------------------------- utils

    /**
     * @return array<string, mixed>
     */
    private function translatableRules(): array
    {
        return [
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function findService(string $uuid): Service
    {
        /** @var Service|null $service */
        $service = Service::query()->where('uuid', $uuid)->first();

        return $service ?? throw new ModelNotFoundException;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthorizationException('Not authenticated.');
    }

    private function require(User $user, Permission $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException('You may not view the catalog.');
        }
    }
}
