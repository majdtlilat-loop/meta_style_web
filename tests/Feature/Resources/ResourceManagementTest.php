<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Booking\Domain\Availability\ResourceFinder;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Resources\Application\Actions\SaveResource;
use App\Modules\Resources\Application\Actions\SaveResourceType;
use App\Modules\Resources\Application\Actions\SetServiceResourceRequirements;
use App\Modules\Resources\Domain\Data\ResourceInput;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Resource management
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §§2-5, 12, 49.
|
| Chairs, rooms and devices, and what each service needs. Tenant-defined,
| because a barbershop, a laser clinic and a hammam share none of this
| vocabulary.
|
*/

/**
 * A bookable day, relative to now.
 *
 * Not a hardcoded date: the booking horizon is 60 days, so a fixed date in a
 * test file quietly stops being bookable as the calendar moves past it — and
 * the failure looks like a resource bug rather than an expired fixture.
 */
function resourceTestDate(): string
{
    return CarbonImmutable::now()->addDays(20)->format('Y-m-d');
}

it('creates, updates and archives a resource type', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();
        $save = app(SaveResourceType::class);

        $type = $save(['en' => 'Laser Machine'], $owner);

        expect((string) $type->name)->toBe('Laser Machine')
            ->and($type->is_active)->toBeTrue();

        $save(['en' => 'Laser Device'], $owner, $type);

        expect((string) $type->fresh()?->name)->toBe('Laser Device');

        $save->archive($type, $owner);

        expect($type->fresh()?->isArchived())->toBeTrue();
    });
});

it('refuses to archive a type a service still requires', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $type = $this->seedResourceType();
        $this->requireResource($seed['service'], $type);

        // A requirement pointing at a retired classification is a service
        // nothing can satisfy, and discovering that at the booking desk is
        // worse than discovering it here.
        expect(fn (): ResourceType => app(SaveResourceType::class)->archive($type, $owner))
            ->toThrow(ValidationException::class);
    });
});

it('creates a resource with a capacity greater than one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $type = $this->seedResourceType('Hammam');

        $resource = app(SaveResource::class)(ResourceInput::fromArray([
            'resource_type' => $type->uuid,
            'branch' => $seed['branch']->uuid,
            'name' => ['en' => 'Shared hammam'],
            // Not every resource is exclusive. A shared space that seats four
            // is bookable by four people (§4).
            'capacity' => 4,
        ]), $owner);

        expect($resource->capacity)->toBe(4);
    });
});

it('refuses a capacity below one', function (): void {
    expect(fn (): ResourceInput => ResourceInput::fromArray([
        'resource_type' => 'x',
        'branch' => 'y',
        'name' => ['en' => 'Nothing'],
        'capacity' => 0,
    ]))->toThrow(ValidationException::class);
});

it('keeps a resource inside its branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $second = $this->seedBranch('Mansour');
        $type = $this->seedResourceType();

        $here = $this->seedResource($type, $seed['branch'], 'Room 1');
        $there = $this->seedResource($type, $second, 'Room 2');

        expect(OperationalResource::query()->bookableAt((int) $seed['branch']->getKey())->pluck('id')->all())
            ->toBe([$here->id])
            ->and(OperationalResource::query()->bookableAt((int) $second->getKey())->pluck('id')->all())
            ->toBe([$there->id]);
    });
});

it('links a resource to an operational department, never a menu category', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $department = $this->seedDepartment('Laser');
        $type = $this->seedResourceType();

        $resource = $this->seedResource($type, $seed['branch'], 'Laser 1', 1, $department);

        expect($resource->department?->getKey())->toBe($department->getKey());
    });
});

it('replaces a service\'s requirements wholesale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $room = $this->seedResourceType('Treatment Room');
        $machine = $this->seedResourceType('Laser Machine');

        $set = app(SetServiceResourceRequirements::class);

        $set($seed['service'], [
            ['type' => $room->uuid, 'quantity' => 1],
            ['type' => $machine->uuid, 'quantity' => 1],
        ], $owner);

        expect(ServiceResourceRequirement::query()->where('service_id', $seed['service']->getKey())->count())
            ->toBe(2);

        // Sent as the COMPLETE list: dropping the machine removes its row
        // rather than leaving a half-configured service behind (§5).
        $set($seed['service'], [['type' => $room->uuid, 'quantity' => 2]], $owner);

        $rows = ServiceResourceRequirement::query()->where('service_id', $seed['service']->getKey())->get();

        expect($rows)->toHaveCount(1)
            ->and($rows->first()?->quantity)->toBe(2);
    });
});

it('refuses the same resource type listed twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $type = $this->seedResourceType();

        expect(fn (): array => app(SetServiceResourceRequirements::class)(
            $seed['service'],
            [['type' => $type->uuid, 'quantity' => 1], ['type' => $type->uuid, 'quantity' => 1]],
            $owner,
        ))->toThrow(ValidationException::class);
    });
});

it('refuses resource management without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $type = $this->seedResourceType();

        $viewer = $this->staffWith([Permission::ResourceView], 'viewer@alpha.test');

        expect(fn (): OperationalResource => app(SaveResource::class)(ResourceInput::fromArray([
            'resource_type' => $type->uuid,
            'branch' => $seed['branch']->uuid,
            'name' => ['en' => 'Chair'],
            'capacity' => 1,
        ]), $viewer))->toThrow(AuthorizationException::class, 'may not manage resources');
    });
});

it('stops new bookings on an archived resource without touching existing ones', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $type = $this->seedResourceType();
        $seed['resource'] = $this->seedResource($type, $seed['branch'], 'Room 1');
        $this->requireResource($seed['service'], $type);

        return $seed;
    });

    $created = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], resourceTestDate(), '10:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
        ])->assertStatus(201);

    $this->asCenter($center['tenant'], function () use ($seed, $created): void {
        $owner = $this->ownerWithCatalogAccess();

        app(SaveResource::class)->archive($seed['resource'], $owner);

        // The booking KEEPS its room. Retiring a resource stops the next
        // booking and touches nothing already made (§12).
        $appointment = Appointment::query()->where('uuid', $created->json('data.uuid'))->firstOrFail();
        $reservations = $appointment->items()->first()?->resourceReservations()->get();

        expect($reservations)->toHaveCount(1)
            ->and($reservations?->first()?->resource_id)->toBe($seed['resource']->id);

        // And it is listed as needing attention, rather than silently moved.
        $affected = app(ResourceFinder::class)
            ->appointmentsOnInactiveResources(CarbonImmutable::now()->subDay());

        expect($affected)->toContain($appointment->id);
    });

    // A NEW booking at another time now fails: nothing of that type is free.
    $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], resourceTestDate(), '14:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Nadia',
            'customer_phone' => '0750 999 0000',
        ])->assertStatus(409);
});

it('snapshots the resource name so a rename does not rewrite history', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $type = $this->seedResourceType('Treatment Room');
        $seed['resource'] = $this->seedResource($type, $seed['branch'], 'Room 1');
        $this->requireResource($seed['service'], $type);

        return $seed;
    });

    $created = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], resourceTestDate(), '10:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
        ])->assertStatus(201);

    $this->asCenter($center['tenant'], function () use ($seed, $created): void {
        $seed['resource']->forceFill(['name' => TranslatedText::fromArray(['en' => 'VIP Room'])])->save();

        $appointment = Appointment::query()->where('uuid', $created->json('data.uuid'))->firstOrFail();
        $reservation = $appointment->items()->first()?->resourceReservations()->first();

        // What the customer was told last month, not what the room is called
        // today (§36).
        expect((string) $reservation?->resource_name)->toBe('Room 1');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
