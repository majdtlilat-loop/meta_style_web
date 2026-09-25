<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\BranchClock;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\ResolvedLine;
use App\Modules\Booking\Domain\Data\StoredLine;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * "When can this be booked?" — the single answer, for every channel.
 *
 * The public menu, the host screen, the customer app and later WhatsApp and
 * RAYAN all ask this class. None of them may compute a slot themselves; that is
 * the boundary the whole product depends on
 * (docs/04-MODULE-BOUNDARIES.md §4.1).
 *
 * ## The constraints it applies
 *
 * Branch open (weekly hours, date exceptions, split shifts, overnight) ·
 * service active, bookable and offered here · variation and add-on durations ·
 * employee active, eligible and at this branch · no conflicting appointment ·
 * NO EMPLOYEE AVAILABILITY BLOCK · RESOURCE CAPACITY for every room, chair and
 * device the service requires · minimum lead time · booking horizon.
 *
 * The last two arrived in Phase 7, and they arrived exactly as Phase 6 said
 * they would: as two more collaborators — {@see BlockFinder} and
 * {@see ResourceAllocator} — alongside {@see BranchCalendar} and
 * {@see ConflictFinder}, with no new branching inside the existing ones. That
 * is the whole reason this class orchestrates named components instead of being
 * one long method (§39, Phase 7 §10).
 *
 * ## What it deliberately does not apply
 *
 * Queue state, attendance, buffers between appointments, deposits. Some need a
 * center to have asked; the queue is Phase 8 and will read Journey rather than
 * feed this.
 *
 * ## Opening hours are checked PER ITEM
 *
 * Not against the visit's span. Once a layout can contain a deliberate gap, the
 * span may legitimately cross a closed interval — a colour at noon and a
 * haircut at four, either side of a siesta closure, is two valid items and one
 * span that is not (Phase 7 corrections §1). {@see Scheduler} carries the
 * worked example.
 *
 * ## Cost
 *
 * A day's availability is a FIXED number of queries regardless of how many
 * slots it produces: the schedule, the eligibility sets, and one bulk load of
 * every conflicting appointment in the range. Everything after that is in
 * memory. Asking the database per candidate start would be tens of thousands
 * of round trips for a week (§34, and a query-count test holds it).
 *
 * Availability is NOT CACHED. A stale "yes" becomes a double booking the
 * customer was told was fine, and a stale "no" is a lost sale nobody can
 * explain. Correct beats fast until there is a measured problem and a proven
 * invalidation story (§34).
 */
final class AvailabilityEngine
{
    public function __construct(
        private readonly BranchCalendar $calendar,
        private readonly LineResolver $resolver,
        private readonly EmployeeAssigner $assigner,
        private readonly ConflictFinder $conflicts,
        private readonly BlockFinder $blocks,
        private readonly ResourceAllocator $resources,
        private readonly ResourceFinder $reservations,
        private readonly Scheduler $scheduler,
        private readonly BookingSettings $settings,
        private readonly StoredLayout $stored,
    ) {}

    /**
     * Bookable start times across a branch-local date range.
     *
     * @return list<AvailabilitySlot>
     *
     * @throws BookingFailed
     */
    public function slots(AvailabilityQuery $query, bool $publicChannel, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        if ($query->isMove()) {
            return $this->slotsForMove($query, $publicChannel, $now);
        }

        $branch = $this->branch($query->branchUuid, $publicChannel);

        $this->assertRangeIsSane($query, $now, $branch->timezone);

        $lines = $this->resolver->resolve($query->lines, $branch, $publicChannel);

        $windows = $this->calendar->windowsForRange($branch, $query->fromDate, $query->toDate);

        if ($windows === []) {
            return [];
        }

        $range = $this->rangeOf($windows);

        $busy = $this->loadBusy($lines, $branch, $range);
        $loads = $this->loadResources($lines, $branch, $range);

        $interval = $this->settings->slotIntervalMinutes();
        $earliest = $now->utc()->addMinutes($this->settings->minLeadMinutes());
        $horizon = $this->horizon($now);
        $total = $this->scheduler->totalMinutes($lines);
        $anchor = $this->scheduler->anchorMinutes($lines);

        $slots = [];
        $seen = [];

        foreach ($windows as $open) {
            foreach ($this->candidateStarts($open, $anchor, $interval) as $start) {
                $key = $start->getTimestamp();

                // A layout with a gap can be anchored by more than one opening
                // interval. The same start must not be offered twice.
                if (isset($seen[$key])) {
                    continue;
                }

                if ($start < $earliest || $start > $horizon) {
                    continue;
                }

                $localDate = BranchClock::localDate($start, $branch->timezone);

                // A window that began the previous evening spills into this
                // range; a window at the end of it can spill past. Only slots
                // whose branch-local date was actually asked for are returned.
                if ($localDate < $query->fromDate || $localDate > $query->toDate) {
                    continue;
                }

                $itemWindows = $this->scheduler->layout($lines, $start);

                // EVERY ITEM inside an opening interval of its own, never the
                // span (Phase 7 corrections §1). Costs no queries: the calendar
                // caches the branch's hours and exceptions per request.
                if (! $this->itemsAreOpen($branch, $itemWindows)) {
                    continue;
                }

                $assignments = $this->assigner->assign($lines, $itemWindows, $busy, $branch);

                if ($assignments === null) {
                    continue;
                }

                if ($this->resources->assign($lines, $itemWindows, $loads, $branch) === null) {
                    continue;
                }

                $seen[$key] = true;

                $slots[] = new AvailabilitySlot(
                    startsAt: $start,
                    endsAt: $start->addMinutes($total),
                    localDate: $localDate,
                    localTime: BranchClock::toLocal($start, $branch->timezone)->format('H:i'),
                    assignments: $assignments,
                );
            }
        }

        // Anchored by different intervals, so not necessarily in order.
        usort($slots, static fn (AvailabilitySlot $a, AvailabilitySlot $b): int => $a->startsAt <=> $b->startsAt);

        return $slots;
    }

    /**
     * Where an EXISTING appointment could move to.
     *
     * The same candidate starts, the same lead time and horizon, the same
     * per-item opening-hours check — but the visit is laid out from its STORED
     * items ({@see StoredLayout}), staffed with the reschedule's rules, and
     * checked against a busy map and a resource load map that IGNORE the
     * appointment itself. Otherwise a sixty-minute booking could never be
     * offered a start thirty minutes later: it would collide with the copy of
     * itself it is moving away from (docs/15-BOOKING.md §4).
     *
     * Advisory. `RescheduleAppointment` re-checks everything under the branch
     * lock, and a slot offered here can still be refused there.
     *
     * @return list<AvailabilitySlot>
     *
     * @throws BookingFailed
     */
    private function slotsForMove(AvailabilityQuery $query, bool $publicChannel, CarbonImmutable $now): array
    {
        // A customer reschedules through their own channel with its own
        // rules; this form of the question is the desk's.
        if ($publicChannel) {
            throw BookingFailed::policy('That appointment cannot be moved.');
        }

        $branch = $this->branch($query->branchUuid, false);

        $this->assertRangeIsSane($query, $now, $branch->timezone);

        $appointment = Appointment::query()
            ->where('uuid', (string) $query->movingAppointmentUuid)
            ->where('branch_id', $branch->getKey())
            ->with(['items.service', 'items.resourceReservations.resource'])
            ->first();

        if (! $appointment instanceof Appointment || $appointment->isTerminal()) {
            throw BookingFailed::policy('That appointment cannot be moved.');
        }

        $lines = $this->stored->of($appointment, $branch);

        if ($lines === []) {
            throw BookingFailed::policy('That appointment has no services to move.');
        }

        $windows = $this->calendar->windowsForRange($branch, $query->fromDate, $query->toDate);

        if ($windows === []) {
            return [];
        }

        [$from, $to] = $this->rangeOf($windows);
        $ignore = (int) $appointment->getKey();

        $busy = $this->loadStoredBusy($lines, $branch, $from, $to, $ignore);
        $loads = $this->loadStoredResources($lines, $from, $to, $ignore);

        $interval = $this->settings->slotIntervalMinutes();
        $earliest = $now->utc()->addMinutes($this->settings->minLeadMinutes());
        $horizon = $this->horizon($now);

        $total = 0;
        $anchor = null;

        foreach ($lines as $line) {
            $ends = $line->offsetMinutes + $line->durationMinutes;
            $total = max($total, $ends);
            $anchor = $anchor === null ? $ends : min($anchor, $ends);
        }

        $candidates = array_map(static fn (StoredLine $line): array => $line->candidates, $lines);
        $held = array_map(static fn (StoredLine $line): array => $line->held, $lines);

        $slots = [];
        $seen = [];

        foreach ($windows as $open) {
            foreach ($this->candidateStarts($open, (int) $anchor, $interval) as $start) {
                $key = $start->getTimestamp();

                if (isset($seen[$key]) || $start < $earliest || $start > $horizon) {
                    continue;
                }

                $localDate = BranchClock::localDate($start, $branch->timezone);

                if ($localDate < $query->fromDate || $localDate > $query->toDate) {
                    continue;
                }

                $itemWindows = array_map(
                    static fn (StoredLine $line): TimeWindow => TimeWindow::of($start->addMinutes($line->offsetMinutes), $line->durationMinutes),
                    $lines,
                );

                if (! $this->itemsAreOpen($branch, $itemWindows)) {
                    continue;
                }

                $assignments = $this->assigner->assignFromCandidates($candidates, $itemWindows, $busy);

                if ($assignments === null || ! $this->resources->heldFit($held, $itemWindows, $loads)) {
                    continue;
                }

                $seen[$key] = true;

                $slots[] = new AvailabilitySlot(
                    startsAt: $start,
                    endsAt: $start->addMinutes($total),
                    localDate: $localDate,
                    localTime: BranchClock::toLocal($start, $branch->timezone)->format('H:i'),
                    assignments: $assignments,
                );
            }
        }

        usort($slots, static fn (AvailabilitySlot $a, AvailabilitySlot $b): int => $a->startsAt <=> $b->startsAt);

        return $slots;
    }

    /**
     * The branch, or a refusal that says nothing about which branches exist.
     *
     * @throws BookingFailed
     */
    public function branch(string $uuid, bool $publicChannel): Branch
    {
        $query = Branch::query()->where('uuid', $uuid);

        // A guest may only book at a branch the center has published. A
        // staff-only back office is operational and must not be reachable from
        // the public menu just because someone has its uuid.
        $branch = $publicChannel
            ? $query->publiclyVisible()->first()
            : $query->active()->first();

        if (! $branch instanceof Branch) {
            throw BookingFailed::policy('That branch is not available for booking.');
        }

        return $branch;
    }

    /**
     * Candidate start times anchored in one opening interval.
     *
     * Aligned to the interval's own start rather than to the clock: a branch
     * that opens at 09:20 offers 09:20, 09:35, 09:50 — not 09:30, which would
     * waste the first ten minutes of every such day.
     *
     * Bounded by the ANCHOR — the end of the earliest-finishing item — rather
     * than by the whole visit. For a sequential booking those are the same
     * number and this behaves exactly as Phase 6 did. For a layout with a gap
     * they differ, and bounding by the whole visit would refuse to anchor a
     * colour at noon merely because the haircut after the gap falls outside the
     * morning shift. What actually accepts or rejects the layout is the
     * per-item calendar check in the caller (Phase 7 corrections §1).
     *
     * The loop is bounded by the window, so slot count is a function of opening
     * hours and granularity, never of the requested range. There is no path
     * here that enumerates minutes (§8).
     *
     * @return list<CarbonImmutable>
     */
    private function candidateStarts(TimeWindow $open, int $anchorMinutes, int $interval): array
    {
        $starts = [];
        $cursor = $open->start;

        // `<=` on the END: a service finishing exactly at closing time is a
        // service that fits.
        while ($cursor->addMinutes($anchorMinutes) <= $open->end) {
            $starts[] = $cursor;
            $cursor = $cursor->addMinutes($interval);
        }

        return $starts;
    }

    /**
     * Is every item of this layout inside an opening interval of its own?
     *
     * @param  list<TimeWindow>  $itemWindows
     */
    private function itemsAreOpen(Branch $branch, array $itemWindows): bool
    {
        foreach ($itemWindows as $window) {
            if (! $this->calendar->coversWindow($branch, $window)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The widest instant range the day's opening intervals cover.
     *
     * @param  list<TimeWindow>  $windows
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function rangeOf(array $windows): array
    {
        $from = $windows[0]->start;
        $to = $windows[0]->end;

        foreach ($windows as $window) {
            $from = $window->start < $from ? $window->start : $from;
            $to = $window->end > $to ? $window->end : $to;
        }

        return [$from, $to];
    }

    /**
     * Every resource reservation in the range, in one query.
     *
     * @param  list<ResolvedLine>  $lines
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array<int, list<array{window: TimeWindow, quantity: int}>>
     */
    private function loadResources(array $lines, Branch $branch, array $range): array
    {
        $candidates = $this->resources->candidateIds($lines, $branch);

        if ($candidates === []) {
            return [];
        }

        return $this->reservations->loads($candidates, $range[0], $range[1]);
    }

    /**
     * Every reason an employee is unavailable in the range, in TWO queries.
     *
     * Appointments and availability blocks are different tables with different
     * lifecycles, and they answer the same question. Merging them into one map
     * here means the assigner keeps its single, simple notion of "busy" and
     * gains no branch for the new source (Phase 7 §13).
     *
     * @param  list<ResolvedLine>  $lines
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array<int, list<TimeWindow>>
     */
    private function loadBusy(array $lines, Branch $branch, array $range): array
    {
        $candidates = $this->assigner->candidateIds($lines, $branch);

        if ($candidates === []) {
            return [];
        }

        [$from, $to] = $range;

        $busy = $this->conflicts->busyWindows($candidates, $from, $to);

        foreach ($this->blocks->blockedWindows($candidates, (int) $branch->getKey(), $from, $to) as $id => $blocked) {
            foreach ($blocked as $window) {
                $busy[$id][] = $window;
            }
        }

        return $busy;
    }

    /**
     * The busy map for a move, WITHOUT the appointment being moved.
     *
     * @param  list<StoredLine>  $lines
     * @return array<int, list<TimeWindow>>
     */
    private function loadStoredBusy(array $lines, Branch $branch, CarbonImmutable $from, CarbonImmutable $to, int $ignore): array
    {
        $ids = [];

        foreach ($lines as $line) {
            foreach ($line->candidates as $id) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $busy = $this->conflicts->busyWindows($ids, $from, $to, $ignore);

        foreach ($this->blocks->blockedWindows($ids, (int) $branch->getKey(), $from, $to) as $id => $blocked) {
            foreach ($blocked as $window) {
                $busy[$id][] = $window;
            }
        }

        return $busy;
    }

    /**
     * The load map of the rooms and devices the moved visit holds, WITHOUT its
     * own reservations.
     *
     * @param  list<StoredLine>  $lines
     * @return array<int, list<array{window: TimeWindow, quantity: int}>>
     */
    private function loadStoredResources(array $lines, CarbonImmutable $from, CarbonImmutable $to, int $ignore): array
    {
        $ids = [];

        foreach ($lines as $line) {
            foreach ($line->held as $held) {
                $ids[] = (int) $held['resource']->getKey();
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : $this->reservations->loads($ids, $from, $to, $ignore);
    }

    private function horizon(CarbonImmutable $now): CarbonImmutable
    {
        return $now->utc()->addDays($this->settings->maxAdvanceDays());
    }

    /**
     * @throws BookingFailed
     */
    private function assertRangeIsSane(AvailabilityQuery $query, CarbonImmutable $now, string $timezone): void
    {
        if ($query->toDate < $query->fromDate) {
            throw BookingFailed::policy('The end of the range is before its start.');
        }

        $days = CarbonImmutable::parse($query->fromDate)->diffInDays(CarbonImmutable::parse($query->toDate)) + 1;

        // An unbounded range is an unbounded query. The limit is the calendar
        // guard rather than the booking horizon, because asking for
        // availability across a quarter is a client bug either way (§22, §34).
        if ($days > $this->settings->maxCalendarDays()) {
            throw BookingFailed::policy('That date range is too wide.', [
                'max_days' => $this->settings->maxCalendarDays(),
            ]);
        }

        $today = BranchClock::localDate($now->utc(), $timezone);

        if ($query->toDate < $today) {
            throw BookingFailed::policy('That date has already passed.');
        }
    }
}
