<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which employees are already busy, and when.
 *
 * ## The overlap rule
 *
 *     existing.starts_at < candidate.ends_at
 *     AND existing.ends_at   > candidate.starts_at
 *
 * Strict on both sides, which is what makes back-to-back bookings work:
 *
 *   existing 10:00–10:30  vs  10:15–10:45  →  CONFLICT
 *   existing 10:00–10:30  vs  10:30–11:00  →  no conflict
 *
 * Deliberately not built from `whereBetween`. The obvious `whereBetween(start,
 * [a, b])` formulation misses an existing appointment that STARTS before the
 * candidate and runs through the whole of it, which is the single most damaging
 * miss available — it double-books the longest treatments
 * (docs/13-ROADMAP.md Phase 6 §10). The same expression lives in
 * {@see TimeWindow::overlaps()} for in-memory checks; if one changes, both must.
 *
 * ## Why it joins to appointments
 *
 * The status lives on the appointment, not the item, and a cancelled or no-show
 * appointment must stop holding its slot the moment it is cancelled.
 * Denormalising status onto items would make that a two-write problem where one
 * write can fail — so the join is deliberate, and it rides the primary key.
 *
 * ## What it does not consider
 *
 * Rooms, chairs, devices and any other shared resource. Those are Phase 7 and
 * arrive as an additional finder alongside this one — not as extra branches
 * inside it (§39).
 */
final class ConflictFinder
{
    /**
     * Every busy interval for a set of employees across a whole date range.
     *
     * The bulk form, for slot generation. Generating a week of slots and asking
     * the database per candidate would be thousands of round trips; loading the
     * range once and comparing in memory is one.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<TimeWindow>> employee id => busy windows
     */
    public function busyWindows(
        array $employeeIds,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $ignoreAppointmentId = null,
    ): array {
        if ($employeeIds === []) {
            return [];
        }

        $rows = $this->baseQuery($ignoreAppointmentId)
            ->whereIn('appointment_items.employee_id', $employeeIds)
            // The same overlap rule, against the range rather than one slot.
            ->where('appointment_items.starts_at', '<', $to->utc())
            ->where('appointment_items.ends_at', '>', $from->utc())
            ->select([
                'appointment_items.employee_id',
                'appointment_items.starts_at',
                'appointment_items.ends_at',
            ])
            ->orderBy('appointment_items.starts_at')
            ->get();

        $windows = [];

        foreach ($rows as $row) {
            /** @var object{employee_id: int|string, starts_at: string, ends_at: string} $row */
            $id = (int) $row->employee_id;

            $windows[$id][] = new TimeWindow(
                CarbonImmutable::parse($row->starts_at, 'UTC'),
                CarbonImmutable::parse($row->ends_at, 'UTC'),
            );
        }

        return $windows;
    }

    /**
     * Does anything at all conflict for this employee?
     *
     * THE AUTHORITATIVE CHECK, run inside the booking transaction while the
     * lock is held. Everything before it is advisory: availability computed a
     * moment ago is a snapshot, and the only answer that counts is the one
     * taken with the write lock in hand (§9).
     */
    public function isEmployeeBusy(int $employeeId, TimeWindow $window, ?int $ignoreAppointmentId = null): bool
    {
        return $this->query($window, $ignoreAppointmentId)
            ->where('appointment_items.employee_id', $employeeId)
            ->exists();
    }

    /**
     * @return Builder
     */
    private function query(TimeWindow $window, ?int $ignoreAppointmentId): mixed
    {
        return $this->baseQuery($ignoreAppointmentId)
            ->where('appointment_items.starts_at', '<', $window->end)
            ->where('appointment_items.ends_at', '>', $window->start);
    }

    /**
     * @return Builder
     */
    private function baseQuery(?int $ignoreAppointmentId): mixed
    {
        $query = DB::connection('tenant')
            ->table('appointment_items')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->whereNotNull('appointment_items.employee_id')
            // Cancelled and no-show appointments release their time; completed
            // ones are in the past. Only these two still occupy the calendar.
            ->whereIn('appointments.status', AppointmentStatus::blockingValues());

        if ($ignoreAppointmentId !== null) {
            // A reschedule must not collide with the appointment it is moving.
            $query->where('appointments.id', '!=', $ignoreAppointmentId);
        }

        return $query;
    }
}
