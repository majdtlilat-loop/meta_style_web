<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Resource-aware availability
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §§6-11, 50.
|
| A room, a chair or a device is a constraint exactly like an employee — except
| that it has CAPACITY, so it is never simply free or busy.
|
*/

function raDate(): string
{
    return CarbonImmutable::now()->addDays(21)->format('Y-m-d');
}

/**
 * Books one service at a branch-local time, as staff.
 */
function bookAt(array $seed, string $time, ?string $phone = null, array $resources = []): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], raDate(), $time),
            lines: [new BookingLine(
                serviceUuid: $seed['service']->uuid,
                resourceUuids: $resources,
            )],
            customer: CustomerRef::details('Sara', $phone ?? '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

/**
 * Queries issued while $work runs.
 *
 * Defined here rather than borrowed from another test file: a helper that only
 * exists when some other file happens to be loaded makes running one test file
 * on its own fail for a reason that has nothing to do with the code.
 */
function raQueryCount(callable $work): int
{
    $count = 0;

    Event::listen(QueryExecuted::class, function () use (&$count): void {
        $count++;
    });

    $work();

    Event::forget(QueryExecuted::class);

    return $count;
}

function raSlots(array $seed): array
{
    return app(AvailabilityEngine::class)->slots(
        new AvailabilityQuery(
            branchUuid: $seed['branch']->uuid,
            fromDate: raDate(),
            toDate: raDate(),
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
        ),
        false,
    );
}

/**
 * A branch with one exclusive treatment room the seeded service requires.
 */
function seedWithRoom(int $capacity = 1, int $rooms = 1): array
{
    $seed = test()->seedBookableCenter();
    $type = test()->seedResourceType('Treatment Room');

    $seed['type'] = $type;
    $seed['rooms'] = [];

    for ($i = 1; $i <= $rooms; $i++) {
        $seed['rooms'][] = test()->seedResource($type, $seed['branch'], "Room {$i}", $capacity, null, $i);
    }

    test()->requireResource($seed['service'], $type);

    // Plenty of stylists, so the employee constraint is never what refuses.
    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

it('refuses a second booking on an exclusive room at an overlapping time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom();

        bookAt($seed, '10:00');

        // Capacity 1, and 10:15 lands inside the 10:00–10:30 booking.
        expect(fn (): Appointment => bookAt($seed, '10:15'))
            ->toThrow(BookingFailed::class);
    });
});

it('accepts a booking that starts exactly when the last one ends', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom();

        bookAt($seed, '10:00');

        // The half-open rule, all the way down to the room: 10:30 is free after
        // a 10:00–10:30 booking.
        expect(bookAt($seed, '10:30'))->toBeInstanceOf(Appointment::class);
    });
});

it('lets a shared resource take as many at once as its capacity allows', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom(capacity: 3);

        bookAt($seed, '10:00');
        bookAt($seed, '10:00');
        bookAt($seed, '10:00');

        expect(Appointment::query()->count())->toBe(3);

        // The fourth exceeds it.
        expect(fn (): Appointment => bookAt($seed, '10:00'))->toThrow(BookingFailed::class);
    });
});

it('spreads one requirement across several resources of the same type', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom(capacity: 1, rooms: 2);

        $first = bookAt($seed, '10:00');
        $second = bookAt($seed, '10:00');

        $roomOf = static fn (Appointment $a): int => (int) $a->items()->first()
            ?->resourceReservations()->first()?->resource_id;

        // Deterministic order — Room 1 then Room 2 — so an idempotent retry
        // produces the room the confirmation already named (§11).
        expect($roomOf($first))->toBe($seed['rooms'][0]->id)
            ->and($roomOf($second))->toBe($seed['rooms'][1]->id);
    });
});

it('needs EVERY required type to be free, not just one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom();

        // A second requirement with no resource behind it at all.
        $machine = $this->seedResourceType('Laser Machine');
        $this->requireResource($seed['service'], $machine);

        // One missing requirement rejects the WHOLE booking — a visit is one
        // arrival, and half a service is worse than saying no (§4).
        expect(fn (): Appointment => bookAt($seed, '10:00'))->toThrow(BookingFailed::class);

        expect(raSlots($seed))->toBe([]);
    });
});

it('ignores a resource that belongs to another branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $elsewhere = $this->seedBranch('Mansour');
        $type = $this->seedResourceType();

        $this->seedResource($type, $elsewhere, 'Room at the other branch');
        $this->requireResource($seed['service'], $type);

        // The room exists, and not here.
        expect(fn (): Appointment => bookAt($seed, '10:00'))->toThrow(BookingFailed::class);
    });
});

it('removes the slots a resource booking occupies from public availability', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom();

        $before = count(raSlots($seed));

        bookAt($seed, '10:00');

        $after = array_map(
            static fn ($slot): string => $slot->localTime,
            raSlots($seed),
        );

        expect(count($after))->toBeLessThan($before)
            // 10:00 and 10:15 both overlap a 10:00–10:30 booking; 10:30 does not.
            ->and($after)->not->toContain('10:00')
            ->and($after)->not->toContain('10:15')
            ->and($after)->toContain('10:30');
    });
});

it('honours a specific resource, or refuses — never substitutes', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom(capacity: 1, rooms: 2);

        // Staff pin Room 2.
        $booking = bookAt($seed, '10:00', null, [$seed['rooms'][1]->uuid]);

        expect((int) $booking->items()->first()?->resourceReservations()->first()?->resource_id)
            ->toBe($seed['rooms'][1]->id);

        // Pinned again while it is busy: refused, NOT quietly moved to Room 1,
        // which is free. Whoever named the room had a reason (§11).
        expect(fn (): Appointment => bookAt($seed, '10:00', null, [$seed['rooms'][1]->uuid]))
            ->toThrow(BookingFailed::class);
    });
});

it('re-checks capacity inside the booking transaction', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom();

        /*
         * Availability is a snapshot. Between reading it and booking, somebody
         * else takes the room — and the authoritative check under the branch
         * lock is what catches that (§8).
         */
        $slots = raSlots($seed);

        expect($slots)->not->toBeEmpty();

        bookAt($seed, '10:00');

        expect(fn (): Appointment => bookAt($seed, '10:00'))->toThrow(BookingFailed::class);
    });
});

it('revalidates resources when an appointment is moved', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom();

        $first = bookAt($seed, '10:00');
        $second = bookAt($seed, '14:00');

        $engine = app(BookingEngine::class);
        $actor = BookingActor::staff($this->ownerWithCatalogAccess());

        // Moving the 14:00 booking onto the 10:00 one needs the same room at
        // the same time. Refused, and NOTHING is left half-applied (§35).
        expect(fn (): Appointment => $engine->reschedule(
            $second,
            $this->localTime($seed['branch'], raDate(), '10:00'),
            $actor,
        ))->toThrow(BookingFailed::class);

        expect($second->fresh()?->starts_at?->format('H:i'))
            ->toBe($second->starts_at->format('H:i'))
            ->and($second->items()->first()?->resourceReservations()->count())->toBe(1);

        // A move to a genuinely free time works.
        $moved = $engine->reschedule(
            $second,
            $this->localTime($seed['branch'], raDate(), '15:00'),
            $actor,
        );

        expect($moved->items()->first()?->resourceReservations()->count())->toBe(1);

        unset($first);
    });
});

it('answers a day of resource-aware availability in a fixed number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = seedWithRoom(capacity: 2, rooms: 3);

        // Warm the branch lookup so the count is about the engine, not about
        // the first-touch of unrelated caches.
        Branch::query()->whereKey($seed['branch']->getKey())->first();

        $baseline = raQueryCount(fn () => raSlots($seed));

        for ($hour = 10; $hour <= 12; $hour++) {
            bookAt($seed, sprintf('%02d:00', $hour));
        }

        $loaded = raQueryCount(fn () => raSlots($seed));

        /*
         * The count must not GROW with the number of bookings. Resource
         * reservations are loaded once for the whole range; asking per
         * candidate slot per resource is the pattern §40 exists to prevent, and
         * it would show up here as a count that climbs with every booking.
         *
         * Not asserted as exactly equal: a fuller day filters some candidates
         * out before the allocator is ever consulted, so the count may legally
         * fall. Only an increase is a regression.
         */
        expect($loaded)->toBeLessThanOrEqual($baseline);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
