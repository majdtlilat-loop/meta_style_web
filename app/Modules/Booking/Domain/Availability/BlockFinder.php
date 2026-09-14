<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\TimeWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * When employees are unavailable for reasons that are not appointments.
 *
 * Breaks, training, personal time — the Phase 7 answer to a Phase 6 gap, where
 * the engine knew about bookings and nothing else, so a stylist's lunch hour
 * was bookable (docs/13-ROADMAP.md Phase 7 §13).
 *
 * ## A separate finder, not a branch inside ConflictFinder
 *
 * Phase 6 wrote down what should happen when a second constraint arrived: "an
 * additional finder alongside this one — not as extra branches inside it".
 * Appointments and blocks answer the same question from different tables with
 * different lifecycles, and the class that merges them is the assigner.
 *
 * ## Bulk, always
 *
 * One query for every employee across the whole date range. The per-slot form
 * of this question — "is Ahmed on a break at 10:15? at 10:30? at 10:45?" — is
 * how a week of availability becomes thousands of round trips (§39).
 */
final class BlockFinder
{
    /**
     * Every blocked interval for a set of employees across a date range.
     *
     * Branch-scoped: a block exists at a branch, and a stylist blocked at
     * Karrada is still bookable at Mansour that afternoon. That is the whole
     * reason `branch_id` is required on the table rather than nullable
     * (Phase 7 corrections §2).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<TimeWindow>> employee id => blocked windows
     */
    public function blockedWindows(
        array $employeeIds,
        int $branchId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::connection('tenant')
            ->table('employee_availability_blocks')
            ->whereIn('employee_id', $employeeIds)
            ->where('branch_id', $branchId)
            // The one overlap rule, against the range rather than one slot.
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->select(['employee_id', 'starts_at', 'ends_at'])
            ->orderBy('starts_at')
            ->get();

        $windows = [];

        foreach ($rows as $row) {
            /** @var object{employee_id: int|string, starts_at: string, ends_at: string} $row */
            $windows[(int) $row->employee_id][] = new TimeWindow(
                CarbonImmutable::parse($row->starts_at, 'UTC'),
                CarbonImmutable::parse($row->ends_at, 'UTC'),
            );
        }

        return $windows;
    }

    /**
     * Is this employee blocked during this window?
     *
     * The authoritative single check, run inside the booking transaction while
     * the branch lock is held. Everything the availability query decided is a
     * snapshot; a block written since is exactly what this catches (§9).
     */
    public function isBlocked(int $employeeId, int $branchId, TimeWindow $window): bool
    {
        return DB::connection('tenant')
            ->table('employee_availability_blocks')
            ->where('employee_id', $employeeId)
            ->where('branch_id', $branchId)
            ->where('starts_at', '<', $window->end)
            ->where('ends_at', '>', $window->start)
            ->exists();
    }

    /**
     * Blocks that overlap booked time at a branch.
     *
     * "You have just blocked Thursday afternoon; these three customers are
     * booked in it." Surfaced rather than acted on — moving somebody's customer
     * is a decision the center makes (§14).
     *
     * @return list<int> appointment ids
     */
    public function appointmentsInsideBlocks(int $branchId, CarbonImmutable $from): array
    {
        /** @var list<int> $ids */
        $ids = DB::connection('tenant')
            ->table('appointment_items')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->join(
                'employee_availability_blocks as blocks',
                'blocks.employee_id',
                '=',
                'appointment_items.employee_id'
            )
            ->where('appointments.branch_id', $branchId)
            ->where('blocks.branch_id', $branchId)
            ->where('appointment_items.starts_at', '>=', $from->utc())
            ->whereColumn('blocks.starts_at', '<', 'appointment_items.ends_at')
            ->whereColumn('blocks.ends_at', '>', 'appointment_items.starts_at')
            ->distinct()
            ->pluck('appointments.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
