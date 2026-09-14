<?php

declare(strict_types=1);

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The Availability Engine
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§6, 7, 8, 10, 41.
|
| The engine is the single source of truth for "when can this be booked", so
| these tests are the specification of what a bookable time means: the branch is
| open for the WHOLE visit inside ONE interval, the service is offered here, an
| eligible active employee assigned to this branch is free, and no existing
| appointment overlaps.
|
| A fixed "now" throughout. Availability that depends on the wall clock of the
| machine running the suite is a test that fails at midnight.
|
*/

/** A Wednesday, deliberately far from any month or year boundary. */
const AVAILABILITY_DATE = '2026-10-14';

function availabilityNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC');
}

/**
 * @param  list<BookingLine>|null  $lines
 * @return list<AvailabilitySlot>
 */
function slotsFor(Branch $branch, Service $service, ?array $lines = null, ?string $date = null): array
{
    return app(AvailabilityEngine::class)->slots(
        new AvailabilityQuery(
            branchUuid: $branch->uuid,
            lines: $lines ?? [new BookingLine(serviceUuid: $service->uuid)],
            fromDate: $date ?? AVAILABILITY_DATE,
            toDate: $date ?? AVAILABILITY_DATE,
        ),
        publicChannel: false,
        now: availabilityNow(),
    );
}

/**
 * @param  list<AvailabilitySlot>  $slots
 * @return list<string>
 */
function slotTimes(array $slots): array
{
    return array_map(static fn (AvailabilitySlot $slot): string => $slot->localTime, $slots);
}

it('offers slots across an open day, on the configured grid', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $times = slotTimes(slotsFor($seed['branch'], $seed['service']));

        // 09:00 to 17:00, a 30-minute service, 15-minute grid: the last start
        // that still finishes by closing is 16:30.
        expect($times)->toContain('09:00', '09:15', '16:30')
            ->and($times)->not->toContain('16:45')
            ->and($times[0])->toBe('09:00');
    });
});

it('honours the center slot interval rather than a hardcoded one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $this->bookingSettings(['slot_interval_minutes' => 30]);

        $times = slotTimes(slotsFor($seed['branch'], $seed['service']));

        expect($times)->toContain('09:00', '09:30')
            // The whole point of the setting: no 15-minute starts at all.
            ->and($times)->not->toContain('09:15');
    });
});

it('offers nothing on a day the branch is closed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $seed['branch']->workingHours()->where('day_of_week', 3)->delete();
        $seed['branch']->unsetRelation('workingHours');

        expect(slotsFor($seed['branch'], $seed['service']))->toBe([]);
    });
});

it('refuses a visit that would cross the gap in split working hours', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // 09:00–13:00 and 16:00–22:00 — the normal shape in this market.
        $this->openSplit($seed['branch']);

        $times = slotTimes(slotsFor($seed['branch'], $seed['service']));

        // 12:30 finishes exactly at closing and is fine. 12:45 would run to
        // 13:15, across the closed afternoon — refused even though 12:45 is
        // plainly inside opening hours (§7).
        expect($times)->toContain('12:30', '16:00', '21:30')
            ->and($times)->not->toContain('12:45')
            ->and($times)->not->toContain('13:00')
            ->and($times)->not->toContain('15:45');
    });
});

it('offers the early hours of a day covered by the previous evening', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // 20:00 until 02:00. A barber open past midnight is one row, and the
        // customer at 00:30 is in a shop that opened yesterday (§7).
        $this->openOvernight($seed['branch']);

        // The NEXT day, so every slot is in the future relative to the fixed
        // "now" of 09:00 local. Its first hours belong to the interval that
        // opened the evening before.
        $times = slotTimes(slotsFor($seed['branch'], $seed['service'], null, '2026-10-15'));

        expect($times)->toContain('00:00', '01:00', '20:00')
            // 01:30 + 30 minutes lands exactly on closing.
            ->and($times)->toContain('01:30')
            ->and($times)->not->toContain('02:00')
            ->and($times)->not->toContain('19:45');
    });
});

it('offers nothing on a date the branch closed for a holiday', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $this->closeOn($seed['branch'], AVAILABILITY_DATE);

        expect(slotsFor($seed['branch'], $seed['service']))->toBe([])
            // The next day is untouched: an exception is one date.
            ->and(slotsFor($seed['branch'], $seed['service'], null, '2026-10-15'))->not->toBe([]);
    });
});

it('uses special opening hours in place of the weekly pattern', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $this->specialHoursOn($seed['branch'], AVAILABILITY_DATE, '18:00', '21:00');

        $times = slotTimes(slotsFor($seed['branch'], $seed['service']));

        expect($times)->toContain('18:00', '20:30')
            // The normal 09:00 opening does not apply that day.
            ->and($times)->not->toContain('09:00')
            ->and($times)->not->toContain('21:00');
    });
});

it('offers nothing for a service the branch does not provide', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $other = $this->seedBranch('Mansour');

        // Restricted to the OTHER branch, so the pivot exists and this branch
        // is not in it.
        $seed['service']->forceFill(['available_at_all_branches' => false])->save();
        $seed['service']->branches()->sync([$other->id]);

        expect(fn (): array => slotsFor($seed['branch'], $seed['service']))
            ->toThrow(BookingFailed::class, 'not offered at that branch');
    });
});

it('offers nothing for an inactive service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $seed['service']->forceFill(['is_active' => false])->save();

        expect(fn (): array => slotsFor($seed['branch'], $seed['service']))
            ->toThrow(BookingFailed::class, 'not available');
    });
});

it('offers nothing when no employee is eligible for the service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // The stylist works here and is active — they simply do not perform
        // this service. Eligibility is its own constraint (§6).
        $seed['service']->eligibleEmployees()->detach();

        expect(slotsFor($seed['branch'], $seed['service']))->toBe([]);
    });
});

it('offers nothing when the only eligible employee works at another branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $other = $this->seedBranch('Mansour');

        $seed['employee']->branches()->sync([$other->id]);

        expect(slotsFor($seed['branch'], $seed['service']))->toBe([]);
    });
});

it('offers nothing when the only eligible employee is inactive', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $seed['employee']->forceFill(['status' => EmployeeStatus::Inactive])->save();

        expect(slotsFor($seed['branch'], $seed['service']))->toBe([]);
    });
});

it('refuses a named employee who does not perform the service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        /** @var Employee $other */
        $other = Employee::query()->create([
            'name' => TranslatedText::fromArray(['en' => 'Sara']),
            'status' => EmployeeStatus::Active,
        ]);
        $other->branches()->attach($seed['branch']->id);

        expect(fn (): array => slotsFor(
            $seed['branch'],
            $seed['service'],
            [new BookingLine(serviceUuid: $seed['service']->uuid, employeeUuid: $other->uuid)],
        ))->toThrow(BookingFailed::class, 'does not perform this service');
    });
});

it('shortens the day by a variation that takes longer than the base service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $variation = $seed['service']->variations()->create([
            'name' => TranslatedText::fromArray(['en' => 'Long']),
            'duration_minutes' => 90,
            'is_active' => true,
            'sort_order' => 9,
        ]);

        $times = slotTimes(slotsFor(
            $seed['branch'],
            $seed['service'],
            [new BookingLine(serviceUuid: $seed['service']->uuid, variationUuid: $variation->uuid)],
        ));

        // 90 minutes, closing at 17:00: the last start is 15:30.
        expect($times)->toContain('15:30')
            ->and($times)->not->toContain('15:45')
            ->and($times)->not->toContain('16:30');
    });
});

it('shortens the day by the duration an add-on contributes', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // The seeded add-on is 10 minutes: a 30-minute service becomes 40.
        $times = slotTimes(slotsFor(
            $seed['branch'],
            $seed['service'],
            [new BookingLine(serviceUuid: $seed['service']->uuid, addonUuids: [$seed['addon']->uuid])],
        ));

        expect($times)->toContain('16:15')
            ->and($times)->not->toContain('16:30');
    });
});

it('removes only the slots an existing appointment actually overlaps', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // 10:00–10:30, held by the one eligible stylist.
        $appointment = Appointment::query()->create([
            'customer_id' => $this->seedCustomer()->id,
            'branch_id' => $seed['branch']->id,
            'status' => AppointmentStatus::Booked,
            'source' => 'staff',
            'starts_at' => $this->localTime($seed['branch'], AVAILABILITY_DATE, '10:00'),
            'ends_at' => $this->localTime($seed['branch'], AVAILABILITY_DATE, '10:30'),
            'booked_timezone' => $seed['branch']->timezone,
        ]);

        $appointment->items()->create([
            'service_id' => $seed['service']->id,
            'employee_id' => $seed['employee']->id,
            'employee_selection' => 'specific',
            'position' => 0,
            'starts_at' => $appointment->starts_at,
            'ends_at' => $appointment->ends_at,
            'duration_minutes' => 30,
            'price_minor' => 20000,
            'currency' => 'IQD',
            'service_name' => $seed['service']->name,
        ]);

        $times = slotTimes(slotsFor($seed['branch'], $seed['service']));

        // THE OVERLAP RULE (§10). 09:45–10:15 and 10:15–10:45 overlap; 09:30
        // ends exactly when the booking starts and 10:30 starts exactly when it
        // ends — both are fine, and a salon depends on that.
        // One needle per negation: `not->toContain(a, b)` passes when EITHER is
        // missing, so a multi-needle negation proves almost nothing.
        expect($times)->not->toContain('09:45')
            ->not->toContain('10:00')
            ->not->toContain('10:15')
            ->and($times)->toContain('09:30', '10:30');
    });
});

it('releases the slots of a cancelled appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = Appointment::query()->create([
            'customer_id' => $this->seedCustomer()->id,
            'branch_id' => $seed['branch']->id,
            // Cancelled appointments must stop holding time the moment they are
            // cancelled, or a center's book silently fills up with ghosts.
            'status' => AppointmentStatus::Cancelled,
            'source' => 'staff',
            'starts_at' => $this->localTime($seed['branch'], AVAILABILITY_DATE, '10:00'),
            'ends_at' => $this->localTime($seed['branch'], AVAILABILITY_DATE, '10:30'),
            'booked_timezone' => $seed['branch']->timezone,
        ]);

        $appointment->items()->create([
            'service_id' => $seed['service']->id,
            'employee_id' => $seed['employee']->id,
            'employee_selection' => 'specific',
            'position' => 0,
            'starts_at' => $appointment->starts_at,
            'ends_at' => $appointment->ends_at,
            'duration_minutes' => 30,
            'price_minor' => 20000,
            'currency' => 'IQD',
            'service_name' => $seed['service']->name,
        ]);

        expect(slotTimes(slotsFor($seed['branch'], $seed['service'])))->toContain('10:00');
    });
});

it('respects the minimum lead time and the booking horizon', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // "Now" is 06:00 UTC = 09:00 in Baghdad, so a two-hour lead time should
        // remove the morning.
        $this->bookingSettings(['min_lead_minutes' => 120]);

        $times = slotTimes(slotsFor($seed['branch'], $seed['service']));

        expect($times)->not->toContain('09:00')
            ->not->toContain('10:45')
            ->and($times)->toContain('11:00');

        $this->bookingSettings(['min_lead_minutes' => 0, 'max_advance_days' => 1]);

        expect(slotsFor($seed['branch'], $seed['service'], null, '2026-10-20'))->toBe([]);
    });
});

it('refuses a date range wider than the calendar guard allows', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        expect(fn (): array => app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                fromDate: AVAILABILITY_DATE,
                // An unbounded range is an unbounded query (§34).
                toDate: '2027-10-14',
            ),
            publicChannel: false,
            now: availabilityNow(),
        ))->toThrow(BookingFailed::class, 'too wide');
    });
});

it('hides a service the center marked as not bookable online, from guests only', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $seed['service']->forceFill(['is_online_bookable' => false])->save();

        $query = new AvailabilityQuery(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
            fromDate: AVAILABILITY_DATE,
            toDate: AVAILABILITY_DATE,
        );

        // A guest is refused…
        expect(fn (): array => app(AvailabilityEngine::class)
            ->slots($query, publicChannel: true, now: availabilityNow()))
            ->toThrow(BookingFailed::class, 'cannot be booked online');

        // …and reception, taking the booking over the phone, is not. That is
        // exactly what the flag means (§6).
        expect(app(AvailabilityEngine::class)->slots($query, publicChannel: false, now: availabilityNow()))
            ->not->toBe([]);
    });
});

it('applies the overlap rule with strict inequalities on both sides', function (): void {
    // The rule itself, in isolation. Every conflict query in the engine is this
    // expression in SQL; a change to one has to be a change to both (§10).
    $existing = new TimeWindow(
        CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-14 10:30:00', 'UTC'),
    );

    $overlapping = new TimeWindow(
        CarbonImmutable::parse('2026-10-14 10:15:00', 'UTC'),
        CarbonImmutable::parse('2026-10-14 10:45:00', 'UTC'),
    );

    $adjacent = new TimeWindow(
        CarbonImmutable::parse('2026-10-14 10:30:00', 'UTC'),
        CarbonImmutable::parse('2026-10-14 11:00:00', 'UTC'),
    );

    $enclosing = new TimeWindow(
        CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'),
    );

    expect($existing->overlaps($overlapping))->toBeTrue()
        ->and($overlapping->overlaps($existing))->toBeTrue()
        ->and($existing->overlaps($adjacent))->toBeFalse()
        ->and($adjacent->overlaps($existing))->toBeFalse()
        // The case a naive `whereBetween(start, [a, b])` misses: an existing
        // appointment that starts before and runs through the whole candidate.
        ->and($existing->overlaps($enclosing))->toBeTrue()
        ->and($enclosing->overlaps($existing))->toBeTrue();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
