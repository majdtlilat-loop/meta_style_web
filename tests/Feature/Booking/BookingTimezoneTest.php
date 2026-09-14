<?php

declare(strict_types=1);

use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingLine;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Time
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§7, 37 · docs/10-API-FOUNDATION.md §8.
|
| Iraq does not observe daylight saving, so nothing in the launch market would
| ever exercise a DST transition. That is the reason to test one: the bug would
| first appear in a market nobody was looking at, in a branch somebody added a
| year after this code was written.
|
| `America/New_York` is used because both transitions are unambiguous there:
| 2026-03-08 skips 02:00→03:00, and 2026-11-01 repeats 01:00→02:00.
|
*/

it('converts a branch-local wall clock to the right instant', function (): void {
    // Baghdad is UTC+3 all year, so 10:00 local is 07:00Z. If this ever drifts,
    // every "today's bookings" query in the product is wrong by three hours.
    $utc = BranchClock::toUtc('2026-10-14', 10 * 60, 'Asia/Baghdad');

    expect($utc)->not->toBeNull()
        ->and($utc->format('Y-m-d H:i'))->toBe('2026-10-14 07:00')
        ->and(BranchClock::localDate($utc, 'Asia/Baghdad'))->toBe('2026-10-14');
});

it('reads the branch-local day, not the server day', function (): void {
    // 22:30 UTC is already tomorrow in Baghdad. A calendar that answered "which
    // day is this" from the server would put the appointment on the wrong page.
    $instant = CarbonImmutable::parse('2026-10-14 22:30:00', 'UTC');

    expect(BranchClock::localDate($instant, 'Asia/Baghdad'))->toBe('2026-10-15')
        ->and(BranchClock::localDate($instant, 'UTC'))->toBe('2026-10-14');
});

it('refuses a local time that does not exist because of daylight saving', function (): void {
    // 2026-03-08, New York: the clock jumps 02:00 → 03:00. 02:30 never happens.
    // PHP would silently move it to 03:30; a booking engine that accepted that
    // would offer a slot at a time that does not exist and then place the
    // appointment an hour from where the calendar showed it.
    expect(BranchClock::toUtc('2026-03-08', 2 * 60 + 30, 'America/New_York'))->toBeNull()

        // The hours either side of the gap are ordinary times.
        ->and(BranchClock::toUtc('2026-03-08', 60, 'America/New_York'))->not->toBeNull()
        ->and(BranchClock::toUtc('2026-03-08', 3 * 60, 'America/New_York'))->not->toBeNull();
});

it('resolves an ambiguous local time to its first occurrence, consistently', function (): void {
    // 2026-11-01, New York: 01:00–02:00 happens twice. PHP picks the first, and
    // that is applied consistently rather than by accident — the offset proves
    // which one was chosen.
    $utc = BranchClock::toUtc('2026-11-01', 60 + 30, 'America/New_York');

    expect($utc)->not->toBeNull()
        // 01:30 EDT (UTC-4) is 05:30Z; the second 01:30 that day is EST (UTC-5)
        // and would be 06:30Z.
        ->and($utc->format('H:i'))->toBe('05:30');
});

it('offers a full day of slots across a spring-forward transition', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter('America/New_York');

        // Open across the gap: 01:00 to 06:00 local on the transition day.
        $this->openEveryDay($seed['branch'], '01:00', '06:00');

        $slots = app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                fromDate: '2026-03-08',
                toDate: '2026-03-08',
            ),
            publicChannel: false,
            now: CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'),
        );

        $times = array_map(static fn (AvailabilitySlot $s): string => $s->localTime, $slots);

        // The interval spans 01:00 to 06:00 on the clock but only FOUR real
        // hours, because 02:00–03:00 does not exist. Nothing in the 02:xx range
        // may be offered.
        expect($times)->toContain('01:00', '03:00', '05:30')
            ->and(array_filter($times, static fn (string $t): bool => str_starts_with($t, '02:')))->toBe([]);

        // And every slot is a real, distinct instant — a shifted duplicate
        // would show up as two slots at the same UTC time.
        $instants = array_map(static fn (AvailabilitySlot $s): string => $s->startsAt->toIso8601String(), $slots);

        expect(count(array_unique($instants)))->toBe(count($instants));
    });
});

it('offers a longer day across a fall-back transition', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter('America/New_York');

        $this->openEveryDay($seed['branch'], '00:00', '06:00');

        $slots = app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                fromDate: '2026-11-01',
                toDate: '2026-11-01',
            ),
            publicChannel: false,
            now: CarbonImmutable::parse('2026-10-25 00:00:00', 'UTC'),
        );

        // 00:00 to 06:00 on the clock is SEVEN real hours that day. Every slot
        // is still a distinct instant, which is what actually matters: a
        // duplicate would let two customers book "01:30" and collide.
        $instants = array_map(static fn (AvailabilitySlot $s): string => $s->startsAt->toIso8601String(), $slots);

        expect(count(array_unique($instants)))->toBe(count($instants))
            ->and(count($slots))->toBeGreaterThan(20);
    });
});

it('keeps two branches in different timezones on their own clocks', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter('Asia/Baghdad');

        $istanbul = $this->seedBranch('Istanbul');
        $istanbul->forceFill(['timezone' => 'Europe/Istanbul'])->save();
        $this->openEveryDay($istanbul, '09:00', '17:00');

        $seed['employee']->branches()->attach($istanbul->id);

        $baghdad = app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery($seed['branch']->uuid, [new BookingLine($seed['service']->uuid)], '2026-10-14', '2026-10-14'),
            publicChannel: false,
            now: CarbonImmutable::parse('2026-10-13 00:00:00', 'UTC'),
        );

        $turkish = app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery($istanbul->uuid, [new BookingLine($seed['service']->uuid)], '2026-10-14', '2026-10-14'),
            publicChannel: false,
            now: CarbonImmutable::parse('2026-10-13 00:00:00', 'UTC'),
        );

        // Both open "at nine". Both are UTC+3 in October, so both first slots
        // are 06:00Z — and the wall clock each reports is its own.
        expect($baghdad[0]->localTime)->toBe('09:00')
            ->and($turkish[0]->localTime)->toBe('09:00')
            ->and($baghdad[0]->startsAt->format('H:i'))->toBe('06:00')
            ->and($turkish[0]->startsAt->format('H:i'))->toBe('06:00');
    });
});

it('falls back to UTC for a corrupt timezone rather than to the server clock', function (): void {
    // A branch with a bad timezone string is a data problem. Failing over to
    // the SERVER's zone would produce answers that look plausible and differ
    // per deployment; UTC is at least obviously, consistently wrong.
    expect(BranchClock::isKnownTimezone('Asia/Baghdad'))->toBeTrue()
        ->and(BranchClock::isKnownTimezone('Nowhere/Imaginary'))->toBeFalse()
        ->and(BranchClock::toUtc('2026-10-14', 600, 'Nowhere/Imaginary')?->format('H:i'))->toBe('10:00');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
