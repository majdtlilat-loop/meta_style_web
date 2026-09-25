<?php

declare(strict_types=1);

use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Where could an existing booking move?
|--------------------------------------------------------------------------
|
| docs/15-BOOKING.md §4. The advisory counterpart of RescheduleAppointment:
| laid out from the STORED items, ignoring the appointment's own current
| place, staffed and roomed with the reschedule's own rules. The consistency
| test at the end moves the booking to every offered time.
|
*/

function bkmDate(int $days = 7): string
{
    return CarbonImmutable::now('Asia/Baghdad')->addDays($days)->format('Y-m-d');
}

/**
 * @param  array<string, mixed>  $seed
 * @param  list<BookingLine>|null  $lines
 */
function bkmBook(array $seed, string $time, ?array $lines = null, string $phone = '+9647507770000'): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: $lines ?? [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], bkmDate(), $time),
            customer: CustomerRef::details('Mover '.$phone, $phone),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

/**
 * @return list<string>
 */
function bkmTimes(Appointment $appointment, string $date): array
{
    return array_map(
        static fn (AvailabilitySlot $slot): string => $slot->localTime,
        app(BookingEngine::class)->availability(AvailabilityQuery::forMove(
            (string) $appointment->branch()->value('uuid'),
            $appointment->uuid,
            $date,
        )),
    );
}

it('offers a move that overlaps the booking\'s own current time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Sixty minutes with the only stylist, 10:00–11:00.
        $long = $this->seedService('Colour', 60, 40000, $seed['employee']);
        $appointment = bkmBook($seed, '10:00', [new BookingLine($long->uuid)]);

        $times = bkmTimes($appointment, bkmDate());

        // The copy of itself it is moving away from does not block it: half an
        // hour later is on offer, and so is its own time.
        expect($times)->toContain('10:30')
            ->and($times)->toContain('10:00');

        // What a NEW booking of the same service may not have: 10:30 is taken.
        $fresh = array_map(
            static fn (AvailabilitySlot $slot): string => $slot->localTime,
            app(BookingEngine::class)->availability(AvailabilityQuery::forDay($seed['branch']->uuid, [new BookingLine($long->uuid)], bkmDate())),
        );

        expect($fresh)->not->toContain('10:30');
    });
});

it('lays the move out from the STORED duration, not today\'s catalog', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Branch closes at 17:00. Booked as 30 minutes…
        $appointment = bkmBook($seed, '10:00');

        // …and the catalog later says two hours. The booking still takes 30.
        $seed['service']->forceFill(['duration_minutes' => 120])->save();

        expect(bkmTimes($appointment, bkmDate()))->toContain('16:30');
    });
});

it('keeps a named stylist and only offers their free times', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        // Asked for Sara by name at 10:00; Sara is also booked at 14:00.
        $named = bkmBook($seed, '10:00', [new BookingLine($seed['service']->uuid, employeeUuid: $sara->uuid)]);
        bkmBook($seed, '14:00', [new BookingLine($seed['service']->uuid, employeeUuid: $sara->uuid)], '+9647508880000');

        $times = bkmTimes($named, bkmDate());

        // Ahmed is free at 14:00, but the customer asked for Sara (§8).
        expect($times)->not->toContain('14:00')
            ->and($times)->toContain('14:30');
    });
});

it('re-checks the rooms the booking already holds instead of re-allocating them', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $second = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $type = $this->seedResourceType('Laser machine');
        $machine = $this->seedResource($type, $seed['branch'], 'Machine 1');
        $this->requireResource($seed['service'], $type);

        // Two stylists, ONE machine. The moving booking holds it at 10:00, and
        // another booking holds it at 12:00.
        $appointment = bkmBook($seed, '10:00');
        bkmBook($seed, '12:00', null, '+9647509990000');

        $times = bkmTimes($appointment, bkmDate());

        // A stylist is free at 12:00, but the machine is not.
        expect($times)->not->toContain('12:00')
            ->and($times)->toContain('12:30');

        unset($machine, $second);
    });
});

it('refuses a move question for a closed booking, from the public channel, or at another branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $other = $this->seedBranch('Mansour');
        $this->openEveryDay($other);

        $appointment = bkmBook($seed, '10:00');
        $query = AvailabilityQuery::forMove($seed['branch']->uuid, $appointment->uuid, bkmDate());

        expect(fn () => app(AvailabilityEngine::class)->slots($query, publicChannel: true))
            ->toThrow(BookingFailed::class)
            // The uuid must belong to the branch asked about.
            ->and(fn () => app(BookingEngine::class)->availability(AvailabilityQuery::forMove($other->uuid, $appointment->uuid, bkmDate())))
            ->toThrow(BookingFailed::class);

        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($this->ownerWithCatalogAccess()));

        expect(fn () => app(BookingEngine::class)->availability($query))->toThrow(BookingFailed::class);
    });
});

it('offers only moves the reschedule accepts', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $beard = $this->seedService('Beard trim', 20, 8000, $seed['employee']);

        // A two-service visit, and a neighbour that fills part of the day.
        $appointment = bkmBook($seed, '11:00', [new BookingLine($seed['service']->uuid), new BookingLine($beard->uuid)]);
        bkmBook($seed, '13:00', null, '+9647501231234');

        $slots = app(BookingEngine::class)->availability(
            AvailabilityQuery::forMove($seed['branch']->uuid, $appointment->uuid, bkmDate()),
        );

        expect($slots)->not->toBeEmpty();

        // The authoritative path takes every advisory answer.
        foreach ($slots as $slot) {
            $moved = app(BookingEngine::class)->reschedule($appointment->refresh(), $slot->startsAt, BookingActor::staff($owner));

            expect($moved->starts_at->utc()->equalTo($slot->startsAt))->toBeTrue();
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
