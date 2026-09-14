<?php

declare(strict_types=1);

namespace App\Kernel\Time;

use Carbon\CarbonImmutable;

/**
 * Peak simultaneous load across a set of quantified time windows.
 *
 * ## Why this is not a SUM
 *
 * The obvious implementation of "can this shared room take one more?" adds up
 * the quantities of everything overlapping the candidate window. It is wrong,
 * and it is wrong in the direction that refuses valid bookings:
 *
 *     Room capacity 2
 *     existing   10:00–10:30  ×1
 *     existing   10:30–11:00  ×1
 *     candidate  10:00–11:00  ×1
 *
 * The two existing bookings never coexist — the peak load is 1, so the
 * candidate fits. A sum says 2, adds the candidate, gets 3, and refuses.
 *
 * The correct question is the maximum load at any INSTANT inside the candidate
 * window, which is a sweep: `+q` when a window opens, `-q` when it closes, walk
 * the events in order, remember the highest running total.
 *
 * ## Half-open, like everything else here
 *
 * Ends are processed BEFORE starts at the same instant, so a booking ending at
 * 10:30 and one starting at 10:30 never count together — the same boundary rule
 * {@see TimeWindow} encodes, and it has to agree with it or a resource would
 * refuse the back-to-back bookings the calendar happily accepts.
 *
 * Pure and dependency-free: no models, no database, no knowledge of what is
 * being counted. Chairs, rooms and devices are the caller's business.
 */
final class Occupancy
{
    /**
     * The highest simultaneous load inside $within.
     *
     * Windows are clipped to $within first: a booking that runs 09:00–18:00
     * contributes to a 10:00–10:30 question for those thirty minutes only, and
     * counting its whole length would be measuring the wrong interval.
     *
     * @param  list<array{window: TimeWindow, quantity: int}>  $loads
     */
    public function peak(array $loads, TimeWindow $within): int
    {
        /** @var list<array{0: CarbonImmutable, 1: int, 2: int}> $events */
        $events = [];

        foreach ($loads as $load) {
            $window = $load['window'];

            if (! $window->overlaps($within)) {
                continue;
            }

            $start = $window->start > $within->start ? $window->start : $within->start;
            $end = $window->end < $within->end ? $window->end : $within->end;

            // Sort key 0 for a release and 1 for a claim, so a window ending at
            // the instant another begins hands over rather than overlapping.
            $events[] = [$end, 0, -$load['quantity']];
            $events[] = [$start, 1, $load['quantity']];
        }

        if ($events === []) {
            return 0;
        }

        usort($events, static function (array $a, array $b): int {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        $running = 0;
        $peak = 0;

        foreach ($events as [$at, $kind, $delta]) {
            unset($at, $kind);

            $running += $delta;

            if ($running > $peak) {
                $peak = $running;
            }
        }

        return $peak;
    }

    /**
     * Is there room for $quantity more, inside $within, given $capacity?
     *
     * @param  list<array{window: TimeWindow, quantity: int}>  $loads
     */
    public function fits(array $loads, TimeWindow $within, int $capacity, int $quantity): bool
    {
        return $this->peak($loads, $within) + $quantity <= $capacity;
    }

    /**
     * How many more units $within could take.
     *
     * Never negative: an over-committed resource — which the capacity checks
     * exist to prevent, but which a capacity REDUCTION can still produce for
     * already-booked time — reports 0 rather than a negative allowance that
     * would read as a credit somewhere downstream.
     *
     * @param  list<array{window: TimeWindow, quantity: int}>  $loads
     */
    public function remaining(array $loads, TimeWindow $within, int $capacity): int
    {
        return max(0, $capacity - $this->peak($loads, $within));
    }
}
