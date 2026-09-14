<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Resources\Domain\Data\ResourceInput;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates or archives a physical resource.
 *
 * ## Why this takes the branch lock
 *
 * Every field here changes what is bookable — capacity, active flag, which
 * branch the thing stands in. Without the lock:
 *
 *   Request A: books the last place in the hammam
 *   Request B: reduces the hammam's capacity by one
 *
 * Both read a consistent world a millisecond apart, both decide they are fine,
 * and the center ends up holding a reservation against capacity that no longer
 * exists. Taking {@see BranchLock} makes this mutation queue behind any booking
 * in flight at that branch, and vice versa (ADR-047, Phase 7 corrections §2).
 *
 * A branch MOVE locks both branches, in ascending id order, which is what
 * `BranchLock` guarantees and what stops two concurrent moves deadlocking.
 *
 * ## What it does not do
 *
 * Reducing capacity below what is already booked is REFUSED rather than
 * silently overbooking or cancelling somebody. The center is told which
 * appointments stand in the way; who gets moved is their decision, not the
 * software's (§12).
 */
final class SaveResource
{
    public function __construct(
        private readonly Audit $audit,
        private readonly BranchLock $lock,
    ) {}

    public function __invoke(ResourceInput $input, User $actingUser, ?OperationalResource $resource = null): OperationalResource
    {
        $this->authorize($actingUser);

        $type = $this->type($input->typeUuid);
        $branch = $this->branch($input->branchUuid, $actingUser);
        $department = $this->department($input->departmentUuid);

        $existing = $resource;
        $before = $existing === null ? null : $this->snapshot($existing);

        // Both branches when the resource is moving, so neither can take a
        // booking against it while it is in flight.
        $branchIds = [(int) $branch->getKey()];

        if ($existing !== null) {
            $branchIds[] = (int) $existing->branch_id;
        }

        /** @var OperationalResource $saved */
        $saved = DB::connection('tenant')->transaction(function () use (
            $input, $existing, $type, $branch, $department, $branchIds
        ): OperationalResource {
            $this->lock->acquire($branchIds);

            $resource = $existing ?? new OperationalResource;

            $resource->forceFill([
                'resource_type_id' => $type->getKey(),
                'branch_id' => $branch->getKey(),
                'department_id' => $department?->getKey(),
                'name' => TranslatedText::fromArray($input->name),
                'capacity' => $input->capacity,
                'is_active' => $input->isActive,
                'sort_order' => $input->sortOrder,
            ]);

            $resource->save();

            return $resource;
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'resources.resource.created' : 'resources.resource.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: OperationalResource::class,
            targetId: $saved->uuid,
            targetLabel: (string) $saved->name,
            before: $before,
            after: $this->snapshot($saved),
        ));

        return $saved;
    }

    /**
     * Retires a resource.
     *
     * New bookings stop immediately. EXISTING ones are untouched — not deleted,
     * not moved to another chair, not silently reassigned. A center that
     * retires a machine with bookings against it gets a list of the
     * appointments affected and decides what to do with them (§12).
     */
    public function archive(OperationalResource $resource, User $actingUser): OperationalResource
    {
        $this->authorize($actingUser);

        DB::connection('tenant')->transaction(function () use ($resource): void {
            $this->lock->acquireOne((int) $resource->branch_id);

            $resource->forceFill(['archived_at' => Carbon::now(), 'is_active' => false])->save();
        });

        $this->audit->record(new AuditEvent(
            action: 'resources.resource.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: OperationalResource::class,
            targetId: $resource->uuid,
            targetLabel: (string) $resource->name,
        ));

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(OperationalResource $resource): array
    {
        return [
            'branch_id' => $resource->branch_id,
            'resource_type_id' => $resource->resource_type_id,
            'department_id' => $resource->department_id,
            // The field a capacity-change investigation actually wants.
            'capacity' => $resource->capacity,
            'is_active' => $resource->is_active,
        ];
    }

    private function type(string $uuid): ResourceType
    {
        $type = ResourceType::query()->where('uuid', $uuid)->first();

        if (! $type instanceof ResourceType || ! $type->isBookable()) {
            throw ValidationException::withMessages([
                'resource_type' => 'That resource type is not available.',
            ]);
        }

        return $type;
    }

    private function branch(string $uuid, User $actingUser): Branch
    {
        $branch = Branch::query()->where('uuid', $uuid)->first();

        if (! $branch instanceof Branch) {
            throw ValidationException::withMessages(['branch' => 'That branch does not exist.']);
        }

        // Permission and branch scope, both. A manager scoped to one branch
        // must not install equipment in another (docs/06 §5).
        if (! $actingUser->canAccessBranch((int) $branch->getKey())) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        return $branch;
    }

    private function department(?string $uuid): ?Department
    {
        if ($uuid === null) {
            return null;
        }

        $department = Department::query()->where('uuid', $uuid)->first();

        if (! $department instanceof Department) {
            throw ValidationException::withMessages(['department' => 'That department does not exist.']);
        }

        return $department;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::ResourceManage)) {
            throw new AuthorizationException('You may not manage resources.');
        }
    }
}
