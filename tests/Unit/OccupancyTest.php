<?php

declare(strict_types=1);

use App\Kernel\Time\Occupancy;
use App\Kernel\Time\TimeWindow;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Peak occupancy
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §8.
|
| The arithmetic behind every capacity decision, tested on its own because it is
| the one part of resource booking that is easy to get plausibly wrong.
|
*/

function window(string $from, string $to): TimeWindow
{
    return new TimeWindow(
        CarbonImmutable::parse("2026-01-01 {$from}", 'UTC'),
        CarbonImmutable::parse("2026-01-01 {$to}", 'UTC'),
    );
}

/**
 * @param  list<array{0: string, 1: string, 2: int}>  $rows
 * @return list<array{window: TimeWindow, quantity: int}>
 */
function loads(array $rows): array
{
    return array_map(
        static fn (array $row): array => ['window' => window($row[0], $row[1]), 'quantity' => $row[2]],
        $rows,
    );
}

it('counts nothing when nothing overlaps', function (): void {
    $peak = (new Occupancy)->peak(loads([['08:00', '09:00', 3]]), window('10:00', '11:00'));

    expect($peak)->toBe(0);
});

it('measures the PEAK, not the sum', function (): void {
    /*
     * THE BUG THIS CLASS EXISTS TO PREVENT.
     *
     *   capacity 2, candidate 10:00–11:00 wanting 1
     *   existing 10:00–10:30 ×1  and  10:30–11:00 ×1
     *
     * The two never coexist, so the peak is 1 and the candidate fits. A sum
     * says 2, adds the candidate, gets 3, and refuses a valid booking.
     */
    $loads = loads([['10:00', '10:30', 1], ['10:30', '11:00', 1]]);
    $candidate = window('10:00', '11:00');

    $occupancy = new Occupancy;

    expect($occupancy->peak($loads, $candidate))->toBe(1)
        ->and($occupancy->fits($loads, $candidate, 2, 1))->toBeTrue();
});

it('adds up loads that genuinely coexist', function (): void {
    $loads = loads([['10:00', '11:00', 1], ['10:15', '10:45', 2]]);

    expect((new Occupancy)->peak($loads, window('10:00', '11:00')))->toBe(3);
});

it('hands over at a shared boundary, like every other interval in the product', function (): void {
    // 10:00–10:30 and 10:30–11:00 never count together: the half-open rule
    // TimeWindow and ConflictFinder both use. A resource that refused this
    // would refuse the back-to-back bookings the calendar happily accepts.
    $loads = loads([['10:00', '10:30', 1]]);

    expect((new Occupancy)->peak($loads, window('10:30', '11:00')))->toBe(0);
});

it('clips a long booking to the window being asked about', function (): void {
    // A booking running 09:00–18:00 contributes to a 10:00–10:30 question for
    // those thirty minutes; counting its whole length would measure the wrong
    // interval.
    $loads = loads([['09:00', '18:00', 1]]);

    expect((new Occupancy)->peak($loads, window('10:00', '10:30')))->toBe(1);
});

it('reports what capacity is left', function (): void {
    $loads = loads([['10:00', '11:00', 1]]);
    $occupancy = new Occupancy;

    expect($occupancy->remaining($loads, window('10:00', '11:00'), 4))->toBe(3)
        ->and($occupancy->fits($loads, window('10:00', '11:00'), 4, 3))->toBeTrue()
        ->and($occupancy->fits($loads, window('10:00', '11:00'), 4, 4))->toBeFalse();
});

it('never reports a negative allowance', function (): void {
    // An over-committed resource — which a capacity REDUCTION can produce for
    // time already booked — reports 0 rather than a negative number that would
    // read as credit somewhere downstream.
    $loads = loads([['10:00', '11:00', 5]]);

    expect((new Occupancy)->remaining($loads, window('10:00', '11:00'), 2))->toBe(0);
});

it('refuses one more than capacity', function (): void {
    $loads = loads([['10:00', '11:00', 1], ['10:00', '11:00', 1]]);

    expect((new Occupancy)->fits($loads, window('10:30', '11:30'), 3, 2))->toBeFalse()
        ->and((new Occupancy)->fits($loads, window('10:30', '11:30'), 3, 1))->toBeTrue();
});
