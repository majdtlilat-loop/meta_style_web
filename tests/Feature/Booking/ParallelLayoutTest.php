<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Non-contiguous and parallel item layouts
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §34, §54, and corrections §1.
|
| A colour with a development wait. Two services running side by side with
| different stylists. Both are shapes a salon actually books, and both were
| impossible while a visit had to be one continuous block.
|
| Sequential is still the default, and public booking is still sequential only.
|
*/

function plDate(): string
{
    return CarbonImmutable::now()->addDays(26)->format('Y-m-d');
}

function plSeed(): array
{
    $seed = test()->seedBookableCenter();

    $seed['second'] = test()->seedService('Colour', 30, 40000, $seed['employee']);
    $seed['sara'] = test()->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
    $seed['second']->eligibleEmployees()->syncWithoutDetaching([$seed['sara']->id]);

    return $seed;
}

/**
 * @param  list<BookingLine>  $lines
 */
function plBook(array $seed, array $lines, string $time = '10:00'): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], plDate(), $time),
            lines: $lines,
            customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

it('lays a booking out back to back when no offset is given', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        $appointment = plBook($seed, [
            new BookingLine(serviceUuid: $seed['service']->uuid),
            new BookingLine(serviceUuid: $seed['second']->uuid),
        ]);

        $items = $appointment->items()->orderBy('position')->get();

        // PHASE 6 BEHAVIOUR, UNCHANGED. Two 30-minute services, contiguous.
        expect($items[0]->ends_at->toIso8601String())->toBe($items[1]->starts_at->toIso8601String())
            ->and($appointment->starts_at->toIso8601String())->toBe($items[0]->starts_at->toIso8601String())
            ->and($appointment->ends_at->toIso8601String())->toBe($items[1]->ends_at->toIso8601String());
    });
});

it('books a deliberate gap between two services', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        // Colour at 10:00, then an hour of development, then the cut.
        $appointment = plBook($seed, [
            new BookingLine(serviceUuid: $seed['second']->uuid, offsetMinutes: 0),
            new BookingLine(serviceUuid: $seed['service']->uuid, offsetMinutes: 60),
        ]);

        $items = $appointment->items()->orderBy('position')->get();

        expect($items[0]->starts_at->format('H:i'))->toBe($items[0]->starts_at->format('H:i'))
            // 30 minutes of work, an hour after the start.
            ->and((int) $items[0]->starts_at->diffInMinutes($items[1]->starts_at))->toBe(60)
            // The HEADER is the union: arrival to departure.
            ->and($appointment->starts_at->toIso8601String())->toBe($items[0]->starts_at->toIso8601String())
            ->and($appointment->ends_at->toIso8601String())->toBe($items[1]->ends_at->toIso8601String());
    });
});

it('accepts a gap that spans a closed period in split working hours', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        // 09:00–13:00 and 16:00–21:00.
        $this->openSplit($seed['branch']);

        /*
         * THE CORRECTION THIS TEST EXISTS FOR.
         *
         *   item A  12:00–12:30   inside the morning shift
         *   item B  16:00–16:30   inside the evening shift
         *   header  12:00–16:30   crosses a closure, and is fine
         *
         * Validating the HEADER would refuse this; validating each item accepts
         * it (corrections §1).
         */
        $appointment = plBook($seed, [
            new BookingLine(serviceUuid: $seed['service']->uuid, offsetMinutes: 0),
            new BookingLine(serviceUuid: $seed['second']->uuid, offsetMinutes: 240),
        ], '12:00');

        $items = $appointment->items()->orderBy('position')->get();

        expect($items)->toHaveCount(2)
            ->and($appointment->localStart()->format('H:i'))->toBe('12:00');
    });
});

it('refuses an item that crosses a closed period, even inside a valid layout', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        $this->openSplit($seed['branch']);

        // The second item starts at 12:45 and runs to 13:15 — straight through
        // closing time. One bad item rejects the whole booking.
        expect(fn (): Appointment => plBook($seed, [
            new BookingLine(serviceUuid: $seed['service']->uuid, offsetMinutes: 0),
            new BookingLine(serviceUuid: $seed['second']->uuid, offsetMinutes: 45),
        ], '12:00'))->toThrow(BookingFailed::class);
    });
});

it('books two services in parallel with different employees', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        $appointment = plBook($seed, [
            new BookingLine(
                serviceUuid: $seed['service']->uuid,
                employeeUuid: $seed['employee']->uuid,
                offsetMinutes: 0,
            ),
            new BookingLine(
                serviceUuid: $seed['second']->uuid,
                employeeUuid: $seed['sara']->uuid,
                offsetMinutes: 0,
            ),
        ]);

        $items = $appointment->items()->orderBy('position')->get();

        expect($items[0]->starts_at->toIso8601String())->toBe($items[1]->starts_at->toIso8601String())
            ->and($items[0]->employee_id)->not->toBe($items[1]->employee_id);
    });
});

it('refuses two parallel services that need the same employee', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        // Nobody is in two places at once — and this is the case Phase 6's
        // within-booking claim tracking was written for.
        expect(fn (): Appointment => plBook($seed, [
            new BookingLine(
                serviceUuid: $seed['service']->uuid,
                employeeUuid: $seed['employee']->uuid,
                offsetMinutes: 0,
            ),
            new BookingLine(
                serviceUuid: $seed['second']->uuid,
                employeeUuid: $seed['employee']->uuid,
                offsetMinutes: 0,
            ),
        ]))->toThrow(BookingFailed::class);
    });
});

it('refuses two parallel services that need the same exclusive room', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        $type = $this->seedResourceType('Treatment Room');
        $this->seedResource($type, $seed['branch'], 'Room 1', 1);

        $this->requireResource($seed['service'], $type);
        $this->requireResource($seed['second'], $type);

        expect(fn (): Appointment => plBook($seed, [
            new BookingLine(
                serviceUuid: $seed['service']->uuid,
                employeeUuid: $seed['employee']->uuid,
                offsetMinutes: 0,
            ),
            new BookingLine(
                serviceUuid: $seed['second']->uuid,
                employeeUuid: $seed['sara']->uuid,
                offsetMinutes: 0,
            ),
        ]))->toThrow(BookingFailed::class);
    });
});

it('allows parallel services in a room with the capacity for both', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        $type = $this->seedResourceType('Hammam');
        $this->seedResource($type, $seed['branch'], 'Shared hammam', 2);

        $this->requireResource($seed['service'], $type);
        $this->requireResource($seed['second'], $type);

        $appointment = plBook($seed, [
            new BookingLine(
                serviceUuid: $seed['service']->uuid,
                employeeUuid: $seed['employee']->uuid,
                offsetMinutes: 0,
            ),
            new BookingLine(
                serviceUuid: $seed['second']->uuid,
                employeeUuid: $seed['sara']->uuid,
                offsetMinutes: 0,
            ),
        ]);

        expect($appointment->items()->count())->toBe(2);
    });
});

it('preserves the shape of a visit when it is moved', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = plSeed();

        $appointment = plBook($seed, [
            new BookingLine(serviceUuid: $seed['second']->uuid, offsetMinutes: 0),
            new BookingLine(serviceUuid: $seed['service']->uuid, offsetMinutes: 60),
        ]);

        $moved = app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], plDate(), '14:00'),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        );

        $items = $moved->items()->orderBy('position')->get();

        // The hour of development is still an hour. Re-flattening the layout
        // would quietly delete a wait somebody booked on purpose (§54).
        expect((int) $items[0]->starts_at->diffInMinutes($items[1]->starts_at))->toBe(60)
            ->and($moved->localStart()->format('H:i'))->toBe('14:00');
    });
});

it('keeps public booking sequential, whatever the request says', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $seed = $this->asCenter($center['tenant'], fn (): array => plSeed());

    $response = $this->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson("/api/v1/menu/{$key}/bookings", [
        'branch' => $seed['branch']->uuid,
        'starts_at' => $this->localTime($seed['branch'], plDate(), '10:00')->toIso8601String(),
        'services' => [
            ['service' => $seed['service']->uuid, 'offset_minutes' => 0],
            // A guest asking for a two-hour gap. Ignored, not honoured: the
            // field is not part of the public contract (§34).
            ['service' => $seed['second']->uuid, 'offset_minutes' => 120],
        ],
        'name' => 'Nadia',
        'phone' => '0750 123 4567',
    ])->assertStatus(201);

    $this->asCenter($center['tenant'], function () use ($response): void {
        $appointment = Appointment::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
        $items = $appointment->items()->orderBy('position')->get();

        expect($items[0]->ends_at->toIso8601String())->toBe($items[1]->starts_at->toIso8601String());
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
