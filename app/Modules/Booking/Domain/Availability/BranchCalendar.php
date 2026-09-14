<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\BranchClock;
use App\Kernel\Time\TimeWindow;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Branches\Domain\Models\BranchHourException;
use App\Modules\Branches\Domain\Models\BranchWorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When a branch is open, as absolute time.
 *
 * Turns the Phase 4 schedule — weekly intervals, plus date exceptions, in the
 * branch's own wall clock — into UTC windows the Booking Engine can compare
 * against. Everything downstream works in absolute time; this is the boundary
 * where local time stops (docs/10-API-FOUNDATION.md §8).
 *
 * ## Three things that are easy to get wrong here
 *
 * **Split shifts.** A day is a LIST of intervals, not one open/close pair.
 * 09:00–13:00 and 16:00–22:00 is the normal shape in this market, and a booking
 * that starts at 12:45 and runs thirty minutes crosses the closed gap. It is
 * refused not because its start is outside opening hours — it is not — but
 * because no single interval contains the whole of it.
 *
 * **Overnight.** `closes_at <= opens_at` means the interval runs past midnight
 * (Phase 4's convention, no column). A barber open 20:00–02:00 on Friday is
 * open at 00:30 on SATURDAY, so a range query has to look at the day before its
 * first date or it will report the shop closed while customers are in it.
 *
 * **DST.** A local opening time can fail to exist. {@see BranchClock} returns
 * null for those, and the start of a window is nudged forward to the first
 * instant that does exist rather than dropping the day: a branch whose 02:00
 * opening falls in a spring-forward gap is still open that morning.
 */
final class BranchCalendar
{
    /** @var array<int, Collection<int, BranchWorkingHour>> */
    private array $hours = [];

    /** @var array<int, array<string, BranchHourException>> */
    private array $exceptions = [];

    /**
     * Open windows whose START falls on the given branch-local date.
     *
     * Keyed on the start date rather than "any window touching this date" so
     * that iterating a range cannot yield the same interval twice.
     *
     * @return list<TimeWindow>
     */
    public function windowsForDate(Branch $branch, string $date): array
    {
        $timezone = $branch->timezone;
        $exception = $this->exceptionFor($branch, $date);

        if ($exception instanceof BranchHourException) {
            if ($exception->is_closed) {
                // Eid, a holiday, a private event. Closed is closed.
                return [];
            }

            if (is_string($exception->opens_at) && is_string($exception->closes_at)) {
                return $this->build($date, $exception->opens_at, $exception->closes_at, $timezone);
            }

            // `is_closed = false` with no times is a half-filled row, not an
            // instruction. Falling back to the weekly pattern is the reading
            // that cannot accidentally close a branch that meant to open.
        }

        $dayOfWeek = BranchClock::localDayOfWeek($date, $timezone);

        $windows = [];

        foreach ($this->hoursFor($branch) as $interval) {
            if ($interval->day_of_week !== $dayOfWeek) {
                continue;
            }

            foreach ($this->build($date, $interval->opens_at, $interval->closes_at, $timezone) as $window) {
                $windows[] = $window;
            }
        }

        usort($windows, static fn (TimeWindow $a, TimeWindow $b): int => $a->start <=> $b->start);

        return $windows;
    }

    /**
     * Every open window relevant to a branch-local date range.
     *
     * Starts a day EARLY on purpose: an interval that began the previous
     * evening and runs past midnight is still open during the first hours of
     * `$from`.
     *
     * @return list<TimeWindow>
     */
    public function windowsForRange(Branch $branch, string $from, string $to): array
    {
        $cursor = CarbonImmutable::parse($from)->subDay();
        $last = CarbonImmutable::parse($to);

        $windows = [];

        while ($cursor <= $last) {
            foreach ($this->windowsForDate($branch, $cursor->format('Y-m-d')) as $window) {
                $windows[] = $window;
            }

            $cursor = $cursor->addDay();
        }

        return $windows;
    }

    /**
     * Is the branch open for the whole of this window?
     *
     * THE QUESTION EVERY BOOKING ASKS, and the one place it should be asked.
     *
     * It looks at the window's own branch-local date AND THE DAY BEFORE.
     * Without the second, an overnight branch refuses a booking its own
     * availability had just offered: a shop open 20:00–02:00 on Friday is open
     * at 00:30 on SATURDAY, but Saturday's own interval does not start until
     * 20:00 that evening, so a containment check against Saturday alone finds
     * nothing (§7).
     *
     * ONE interval must contain the whole window. Two adjacent intervals that
     * happen to touch are still two intervals — though they cannot touch in
     * practice, because an interval running straight into the next would have
     * been stored as one.
     */
    public function coversWindow(Branch $branch, TimeWindow $window): bool
    {
        $date = BranchClock::localDate($window->start, $branch->timezone);
        $previous = CarbonImmutable::parse($date)->subDay()->format('Y-m-d');

        return $this->isOpenThroughout($window, array_merge(
            $this->windowsForDate($branch, $previous),
            $this->windowsForDate($branch, $date),
        ));
    }

    /**
     * @param  list<TimeWindow>  $open
     */
    public function isOpenThroughout(TimeWindow $window, array $open): bool
    {
        foreach ($open as $interval) {
            if ($window->isContainedBy($interval)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<TimeWindow>
     */
    private function build(string $date, string $opensAt, string $closesAt, string $timezone): array
    {
        $open = $this->minutes($opensAt);
        $close = $this->minutes($closesAt);

        if ($close <= $open) {
            // Phase 4's overnight convention: closing at or before opening
            // means the interval runs into the following day.
            $close += 1440;
        }

        // The START may be shifted forward across a DST gap rather than
        // dropped; the END is resolved the same way so the window never
        // collapses. A window that would still be empty is not a window.
        $start = BranchClock::toUtcOrShift($date, $open, $timezone);
        $end = BranchClock::toUtcOrShift($date, $close, $timezone);

        if ($end <= $start) {
            return [];
        }

        return [new TimeWindow($start, $end)];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return ($hours * 60) + $minutes;
    }

    /**
     * @return Collection<int, BranchWorkingHour>
     */
    private function hoursFor(Branch $branch): Collection
    {
        $id = (int) $branch->getKey();

        if (isset($this->hours[$id])) {
            return $this->hours[$id];
        }

        // Loaded once per branch per request. Availability for a week asks this
        // question seven times, and a query each would be seven round trips for
        // one small, entirely static table.
        return $this->hours[$id] = $branch->relationLoaded('workingHours')
            ? $branch->workingHours
            : $branch->workingHours()->get();
    }

    private function exceptionFor(Branch $branch, string $date): ?BranchHourException
    {
        $id = (int) $branch->getKey();

        if (! isset($this->exceptions[$id])) {
            $rows = $branch->relationLoaded('hourExceptions')
                ? $branch->hourExceptions
                : $branch->hourExceptions()->get();

            $map = [];

            foreach ($rows as $row) {
                $map[$row->date->format('Y-m-d')] = $row;
            }

            $this->exceptions[$id] = $map;
        }

        return $this->exceptions[$id][$date] ?? null;
    }
}
