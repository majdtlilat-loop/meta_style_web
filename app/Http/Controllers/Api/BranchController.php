<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Application\Actions\ArchiveBranch;
use App\Modules\Branches\Application\Actions\SaveBranch;
use App\Modules\Branches\Application\Actions\SaveBranchSchedule;
use App\Modules\Branches\Domain\Data\BranchInput;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch management for signed-in staff.
 *
 * Validate → call one Action → return a resource. The Action owns the
 * transaction, the permission check and the audit entry, so this class stays a
 * translation layer between HTTP and the domain (CLAUDE.md, Structure).
 */
final class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, Permission::BranchView);

        $branches = Branch::query()
            ->with('workingHours')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch): array => $this->present($branch))
            ->values()->all();

        return ApiResponse::data(['branches' => $branches]);
    }

    public function store(Request $request, SaveBranch $save): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate($this->rules());

        $branch = $save(BranchInput::fromArray($validated), $user);

        return ApiResponse::data($this->present($branch), 201);
    }

    public function update(string $uuid, Request $request, SaveBranch $save): JsonResponse
    {
        $user = $this->user($request);
        $branch = $this->find($uuid);

        $validated = $request->validate($this->rules());

        return ApiResponse::data($this->present($save(BranchInput::fromArray($validated), $user, $branch)));
    }

    public function schedule(string $uuid, Request $request, SaveBranchSchedule $save): JsonResponse
    {
        $user = $this->user($request);
        $branch = $this->find($uuid);

        $validated = $request->validate([
            'hours' => ['present', 'array'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i'],

            'exceptions' => ['present', 'array'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.is_closed' => ['required', 'boolean'],
            'exceptions.*.opens_at' => ['nullable', 'date_format:H:i'],
            'exceptions.*.closes_at' => ['nullable', 'date_format:H:i'],
            'exceptions.*.note' => ['nullable', 'string', 'max:190'],
        ]);

        /** @var list<array{day_of_week: int, opens_at: string, closes_at: string}> $hours */
        $hours = $validated['hours'];

        /** @var list<array{date: string, is_closed: bool, opens_at?: string|null, closes_at?: string|null, note?: string|null}> $exceptions */
        $exceptions = $validated['exceptions'];

        return ApiResponse::data($this->present($save($branch, $hours, $exceptions, $user)->load('workingHours')));
    }

    public function archive(string $uuid, Request $request, ArchiveBranch $archive): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data($this->present($archive($this->find($uuid), $user)));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            // Translatable fields arrive as `{ "ar": "...", "en": "..." }`
            // (docs/07-LOCALIZATION.md §8). Validating the shape, not the
            // languages: which locales are required is the tenant's choice.
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'array'],
            'address.*' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'timezone'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'map_url' => ['nullable', 'url', 'max:512'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Branch $branch): array
    {
        return [
            'uuid' => $branch->uuid,
            'name' => $branch->name->all(),
            'address' => $branch->address?->all(),
            'timezone' => $branch->timezone,
            'phone' => $branch->phone,
            'whatsapp' => $branch->whatsapp,
            'email' => $branch->email,
            'map_url' => $branch->map_url,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'is_active' => $branch->is_active,
            'is_public' => $branch->is_public,
            'is_main' => $branch->is_main,
            'sort_order' => $branch->sort_order,
            'archived_at' => $branch->archived_at?->toIso8601String(),
            'hours' => $branch->relationLoaded('workingHours')
                ? $branch->workingHours->map(fn ($h): array => [
                    'day_of_week' => $h->day_of_week,
                    'opens_at' => mb_substr($h->opens_at, 0, 5),
                    'closes_at' => mb_substr($h->closes_at, 0, 5),
                    'crosses_midnight' => $h->crossesMidnight(),
                ])->values()->all()
                : [],
        ];
    }

    private function find(string $uuid): Branch
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $uuid)->first();

        // A branch in another tenant is indistinguishable from one that does
        // not exist (docs/08-AUDIT-SECURITY.md §19) — and it cannot be reached
        // anyway, because the query runs on the tenant connection. The renderer
        // maps ModelNotFoundException to a 404 RESOURCE.NOT_FOUND envelope.
        return $branch ?? throw new ModelNotFoundException;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthorizationException('Not authenticated.');
    }

    private function requirePermission(Request $request, Permission $permission): void
    {
        if (! $this->user($request)->hasPermission($permission)) {
            throw new AuthorizationException('You may not view branches.');
        }
    }
}
