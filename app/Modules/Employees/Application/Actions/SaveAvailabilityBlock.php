<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Kernel\Time\TimeWindow;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Domain\Enums\AvailabilityBlockType;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a block on an employee's bookable time.
 *
 * ## Takes the branch lock
 *
 * A block changes who is bookable. Without coordination, a booking that checked
 * availability a millisecond before the block was written commits happily into
 * the middle of somebody's training day — both transactions correct on their
 * own, the outcome wrong. The lock puts them in a queue of two
 * (ADR-047, Phase 7 corrections §2).
 *
 * ## Existing bookings survive
 *
 * A block never cancels or moves an appointment. Blocking Thursday afternoon
 * when Thursday afternoon already has three customers in it returns those three
 * appointments so the person creating the block can see what they have just
 * done — and then decide, because rescheduling somebody's customer is not a
 * decision software makes on its own (§14, and the same reasoning as employee
 * deactivation in Phase 6 §21).
 */
final class SaveAvailabilityBlock
{
    public function __construct(
        private readonly Audit $audit,
        private readonly BranchLock $lock,
    ) {}

    /**
     * Request data, so every key is checked rather than assumed.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        array $data,
        User $actingUser,
        ?EmployeeAvailabilityBlock $block = null,
    ): EmployeeAvailabilityBlock {
        $this->authorize($actingUser);

        // The block being edited must itself be in the actor's branches — a
        // scoped manager may not move somebody else's block into their own.
        if ($block !== null) {
            $this->assertMayTouch($block, $actingUser);
        }

        $employee = $this->employee((string) ($data['employee'] ?? ''));
        $branch = $this->branch((string) ($data['branch'] ?? ''), $actingUser);
        $window = $this->window($data, $branch);

        $this->assertEmployeeWorksAt($employee, $branch);

        $existing = $block;
        $before = $existing === null ? null : $this->snapshot($existing);

        $type = AvailabilityBlockType::tryFrom((string) ($data['type'] ?? 'break'))
            ?? AvailabilityBlockType::Break;

        // Both branches when a block is being moved between them.
        $branchIds = [(int) $branch->getKey()];

        if ($existing !== null) {
            $branchIds[] = (int) $existing->branch_id;
        }

        /** @var EmployeeAvailabilityBlock $saved */
        $saved = DB::connection('tenant')->transaction(function () use (
            $existing, $employee, $branch, $window, $type, $data, $actingUser, $branchIds
        ): EmployeeAvailabilityBlock {
            $this->lock->acquire($branchIds);

            $block = $existing ?? new EmployeeAvailabilityBlock;

            $block->forceFill([
                'employee_id' => $employee->getKey(),
                'branch_id' => $branch->getKey(),
                'starts_at' => $window->start,
                'ends_at' => $window->end,
                'type' => $type,
                'internal_note' => $this->note($data),
                'created_by_user_id' => $block->exists ? $block->created_by_user_id : $actingUser->getKey(),
            ]);

            $block->save();

            return $block;
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null
                ? 'employees.availability_block.created'
                : 'employees.availability_block.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: EmployeeAvailabilityBlock::class,
            targetId: $saved->uuid,
            targetLabel: (string) $employee->name,
            before: $before,
            // The note body is NOT audited — the trail records that a block
            // exists and when, never what somebody typed about a colleague
            // (docs/08-AUDIT-SECURITY.md).
            after: $this->snapshot($saved),
        ));

        return $saved;
    }

    public function delete(EmployeeAvailabilityBlock $block, User $actingUser): void
    {
        $this->authorize($actingUser);
        $this->assertMayTouch($block, $actingUser);

        $snapshot = $this->snapshot($block);
        $uuid = $block->uuid;

        DB::connection('tenant')->transaction(function () use ($block): void {
            // Removing a block makes time bookable again — the same
            // coordination question in the other direction.
            $this->lock->acquireOne((int) $block->branch_id);

            $block->delete();
        });

        $this->audit->record(new AuditEvent(
            action: 'employees.availability_block.deleted',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: EmployeeAvailabilityBlock::class,
            targetId: $uuid,
            before: $snapshot,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EmployeeAvailabilityBlock $block): array
    {
        return [
            'employee_id' => $block->employee_id,
            'branch_id' => $block->branch_id,
            'starts_at' => $block->starts_at->toIso8601String(),
            'ends_at' => $block->ends_at->toIso8601String(),
            'type' => $block->type->value,
        ];
    }

    /**
     * The block's window, in absolute time.
     *
     * Accepts either an ISO-8601 instant or a branch-local `Y-m-d H:i`. The
     * local form goes through {@see BranchClock} like every other wall clock in
     * the product: a time that does not exist because of a DST jump is refused
     * rather than silently shifted into one that does (Phase 6 §7).
     *
     * @param  array<string, mixed>  $data
     */
    private function window(array $data, Branch $branch): TimeWindow
    {
        $start = $this->instant((string) ($data['starts_at'] ?? ''), $branch);
        $end = $this->instant((string) ($data['ends_at'] ?? ''), $branch);

        if ($end <= $start) {
            throw ValidationException::withMessages([
                'ends_at' => __('manager_staff.errors.block_order'),
            ]);
        }

        return new TimeWindow($start, $end);
    }

    private function instant(string $value, Branch $branch): CarbonImmutable
    {
        if ($value === '') {
            throw ValidationException::withMessages(['starts_at' => __('manager_staff.errors.block_window')]);
        }

        // `2026-10-14 13:00` — a branch-local wall clock.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $value) === 1) {
            [$date, $time] = preg_split('/[ T]/', $value) ?: ['', ''];
            [$hours, $minutes] = array_map('intval', explode(':', $time));

            $utc = BranchClock::toUtc($date, $hours * 60 + $minutes, $branch->timezone);

            if ($utc === null) {
                throw ValidationException::withMessages([
                    'starts_at' => __('manager_staff.errors.block_dst'),
                ]);
            }

            return $utc;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => __('manager_staff.errors.block_time')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function note(array $data): ?string
    {
        $note = $data['internal_note'] ?? null;

        if (! is_string($note)) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 2000);
    }

    private function employee(string $uuid): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->first();

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages(['employee' => __('manager_staff.errors.employee_unknown')]);
        }

        return $employee;
    }

    private function branch(string $uuid, User $actingUser): Branch
    {
        $branch = Branch::query()->where('uuid', $uuid)->first();

        if (! $branch instanceof Branch) {
            throw ValidationException::withMessages(['branch' => __('manager_staff.errors.branch_unknown')]);
        }

        if (! $actingUser->canAccessBranch((int) $branch->getKey())) {
            throw new AuthorizationException(__('manager_staff.errors.branch_denied'));
        }

        return $branch;
    }

    /**
     * A block at a branch the employee is not assigned to blocks nothing, so it
     * is a mistake worth naming rather than a row worth writing.
     */
    private function assertEmployeeWorksAt(Employee $employee, Branch $branch): void
    {
        if (! in_array((int) $branch->getKey(), $employee->branchIds(), true)) {
            throw ValidationException::withMessages([
                'branch' => __('manager_staff.errors.block_branch'),
            ]);
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::AvailabilityBlockManage)) {
            throw new AuthorizationException(__('manager_staff.errors.block_denied'));
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function assertMayTouch(EmployeeAvailabilityBlock $block, User $actingUser): void
    {
        if (! $actingUser->canAccessBranch((int) $block->branch_id)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_denied'));
        }
    }
}
