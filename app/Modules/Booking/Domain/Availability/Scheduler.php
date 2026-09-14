<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Data\ResolvedLine;
use Carbon\CarbonImmutable;

/**
 * Lays a multi-service visit out in time.
 *
 * ## Sequential by default, which is Phase 6's behaviour unchanged
 *
 * Haircut then beard then facial: each starts where the last ended, and the
 * visit occupies one continuous block. Every public booking is still exactly
 * this, because no public adapter sets an offset
 * (docs/13-ROADMAP.md Phase 6 §4, Phase 7 §34).
 *
 * ## Offsets, for the layouts a salon actually runs
 *
 * A line may instead declare `offsetMinutes` — where it starts, measured from
 * the beginning of the visit. Two things become expressible:
 *
 *     Colour        10:00–10:30   offset 0
 *     (development)               ← nobody is working; the customer waits
 *     Haircut       11:00–11:30   offset 60
 *
 *     Service A     10:00–10:30   offset 0
 *     Service B     10:00–10:45   offset 0   ← different employee and room
 *
 * The engine still validates everything it validated before: employee
 * conflicts, resource capacity, and branch opening hours. What changes is that
 * two lines may now overlap, which is why the assigners count resources and
 * employees claimed by earlier lines of the same booking.
 *
 * ## The span is the union, and it is NOT an opening-hours requirement
 *
 * The appointment header spans the earliest start to the latest end, because
 * that is the visit's identity — when the customer arrives and when they leave.
 * It is deliberately NOT what opening hours are checked against: with a gap,
 * the span can legitimately cross a closed interval.
 *
 *     Branch open   09:00–13:00 and 16:00–21:00
 *     Item A        12:00–13:00      ← inside the morning shift
 *     Item B        16:00–17:00      ← inside the evening shift
 *     Header span   12:00–17:00      ← crosses a closure, and is fine
 *
 * So {@see BranchCalendar::coversWindow()} is asked about EACH ITEM, never
 * about the span (Phase 7 corrections §1).
 */
final class Scheduler
{
    /**
     * One window per line, in order.
     *
     * @param  list<ResolvedLine>  $lines
     * @return list<TimeWindow>
     */
    public function layout(array $lines, CarbonImmutable $startsAt): array
    {
        $origin = $startsAt->utc();
        $cursor = $origin;
        $windows = [];

        foreach ($lines as $line) {
            $window = TimeWindow::of(
                $line->offsetMinutes === null ? $cursor : $origin->addMinutes($line->offsetMinutes),
                $line->durationMinutes,
            );

            $windows[] = $window;

            // An offset line does not move the sequential cursor backwards: a
            // later line with no offset of its own continues from whichever
            // end is furthest along, which is what "after everything so far"
            // means once lines can overlap.
            $cursor = $window->end > $cursor ? $window->end : $cursor;
        }

        return $windows;
    }

    /**
     * The whole visit, earliest start to latest end.
     *
     * @param  list<ResolvedLine>  $lines
     */
    public function span(array $lines, CarbonImmutable $startsAt): TimeWindow
    {
        return $this->union($this->layout($lines, $startsAt));
    }

    /**
     * The union of a set of item windows.
     *
     * @param  list<TimeWindow>  $windows
     */
    public function union(array $windows): TimeWindow
    {
        $start = $windows[0]->start;
        $end = $windows[0]->end;

        foreach ($windows as $window) {
            $start = $window->start < $start ? $window->start : $start;
            $end = $window->end > $end ? $window->end : $end;
        }

        return new TimeWindow($start, $end);
    }

    /**
     * How long the visit occupies, start to finish.
     *
     * The union's length, not the sum of the durations — with a gap those two
     * differ, and it is the union that decides whether a slot fits in the day.
     *
     * @param  list<ResolvedLine>  $lines
     */
    public function totalMinutes(array $lines): int
    {
        if ($lines === []) {
            return 0;
        }

        return $this->span($lines, CarbonImmutable::parse('2000-01-01 00:00:00', 'UTC'))
            ->durationMinutes();
    }

    /**
     * Minutes from the visit's start to the end of its earliest-finishing item.
     *
     * The bound slot generation uses. A candidate start is only worth
     * considering if at least one item finishes inside the opening interval
     * that produced it — otherwise the same slot would be generated once per
     * interval of the day. Every item is then validated against the calendar
     * individually, which is what actually accepts or rejects the layout
     * (Phase 7 corrections §1).
     *
     * @param  list<ResolvedLine>  $lines
     */
    public function anchorMinutes(array $lines): int
    {
        if ($lines === []) {
            return 0;
        }

        $origin = CarbonImmutable::parse('2000-01-01 00:00:00', 'UTC');
        $earliest = null;

        foreach ($this->layout($lines, $origin) as $window) {
            $ends = (int) $origin->diffInMinutes($window->end);

            if ($earliest === null || $ends < $earliest) {
                $earliest = $ends;
            }
        }

        return $earliest ?? 0;
    }

    /**
     * Does any line declare an explicit position?
     *
     * The public adapters refuse a booking where this is true: a customer
     * choosing their own gaps is a product decision nobody has made, and the
     * flow that would present it does not exist (§34).
     *
     * @param  list<ResolvedLine>  $lines
     */
    public function hasExplicitLayout(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->offsetMinutes !== null) {
                return true;
            }
        }

        return false;
    }
}
