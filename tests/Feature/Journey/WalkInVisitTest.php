<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\JourneySource;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Walk-in visits
|--------------------------------------------------------------------------
|
| docs/16-JOURNEY-RESOURCES.md §22, docs/17-QUEUE.md §2.
|
| A visit nobody booked. `service_journeys.appointment_id` is nullable, and the
| journey carries the customer and branch it can no longer derive — no fake
| appointment, no second customer model, no second set of operational rules.
|
*/

function wiDate(): string
{
    return CarbonImmutable::now()->addDays(31)->format('Y-m-d');
}

function wiSeed(int $capacity = 1): array
{
    $seed = test()->seedBookableCenter();

    $type = test()->seedResourceType('Treatment Room');
    $seed['type'] = $type;
    $seed['room'] = test()->seedResource($type, $seed['branch'], 'Room 1', $capacity);

    test()->requireResource($seed['service'], $type);

    // Enough stylists that an employee constraint is never what refuses.
    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function wiRequest(array $seed, array $overrides = []): WalkInRequest
{
    return new WalkInRequest(
        branchUuid: $overrides['branch'] ?? $seed['branch']->uuid,
        serviceUuids: $overrides['services'] ?? [$seed['service']->uuid],
        customerUuid: $overrides['customer'] ?? null,
        // array_key_exists, not ??: a test that passes an explicit null means
        // "no name", and `??` would quietly hand it the default instead.
        name: array_key_exists('name', $overrides) ? $overrides['name'] : 'Sara Ahmed',
        phone: $overrides['phone'] ?? null,
        employeeUuid: $overrides['employee'] ?? null,
        idempotencyToken: $overrides['token'] ?? null,
    );
}

function wiCreate(array $seed, User $owner, array $overrides = []): ServiceJourney
{
    return app(CreateWalkInVisit::class)(wiRequest($seed, $overrides), $owner);
}

it('starts an operational visit with no appointment at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = wiCreate($seed, $owner);

        expect($journey->appointment_id)->toBeNull()
            ->and($journey->source)->toBe(JourneySource::WalkIn)
            // The two facts it can no longer derive.
            ->and($journey->customer_id)->not->toBeNull()
            ->and((int) $journey->branch_id)->toBe($seed['branch']->id)
            ->and($journey->branchId())->toBe($seed['branch']->id)
            ->and($journey->arrived_at)->not->toBeNull()
            // NO FAKE APPOINTMENT. The booking tables are untouched.
            ->and(Appointment::query()->count())->toBe(0);
    });
});

it('turns each chosen service into a stage with its own snapshot', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        $second = $this->seedService('Beard trim', 20, 15000, $seed['employee']);

        $journey = wiCreate($seed, $owner, [
            'services' => [$seed['service']->uuid, $second->uuid],
        ]);

        $stages = $journey->stages()->get();

        expect($stages)->toHaveCount(2)
            ->and($stages[0]->appointment_item_id)->toBeNull()
            ->and($stages[0]->service_id)->toBe($seed['service']->id)
            ->and($stages[0]->position)->toBe(0)
            ->and($stages[1]->service_id)->toBe($second->id)
            ->and($stages[1]->position)->toBe(1)
            // Snapshots, so a later rename or reprice cannot rewrite the visit.
            ->and((string) $stages[1]->service_name)->toBe('Beard trim')
            ->and($stages[1]->duration_minutes)->toBe(20)
            ->and($stages[1]->price_minor)->toBe(15000)
            // And the accessors hide which kind of stage this is.
            ->and($stages[1]->durationMinutes())->toBe(20)
            ->and($stages[1]->serviceId())->toBe($second->id)
            ->and($stages[1]->isWalkIn())->toBeTrue();

        // A rename afterwards changes nothing about what happened.
        $second->forceFill(['name' => ['en' => 'Beard sculpt']])->save();

        expect((string) $stages[1]->fresh()?->service_name)->toBe('Beard trim');
    });
});

it('reuses the customer a phone number already belongs to', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        /** @var Customer $existing */
        $existing = Customer::query()->create([
            'name' => 'Sara Ahmed',
            'phone' => '+9647512345678',
            'phone_display' => '0751 234 5678',
        ]);

        // Typed differently at the desk. Normalisation is the identity rule,
        // and it is the SAME rule booking uses — not a copy of it.
        $journey = wiCreate($seed, $owner, ['phone' => '07512345678', 'name' => 'Sara']);

        expect((int) $journey->customer_id)->toBe($existing->id)
            // ONE PERSON, ONE RECORD. A walk-in must not fork a regular.
            ->and(Customer::query()->count())->toBe(1)
            // And the existing record is not renamed by whatever was typed.
            ->and($existing->fresh()?->name)->toBe('Sara Ahmed');
    });
});

it('creates a nameable customer for a walk-in with no phone, and no account', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = wiCreate($seed, $owner, ['name' => 'Walk-in Ali']);

        $customer = Customer::query()->find($journey->customer_id);

        expect($customer?->name)->toBe('Walk-in Ali')
            ->and($customer?->phone)->toBeNull()
            ->and($customer?->source->value)->toBe('staff')
            /*
             * A CUSTOMER IS NOT AN ACCOUNT. Walking in is operational
             * behaviour, not registration, and silently creating a login for
             * somebody who never asked for one would be both (ADR-041).
             */
            ->and(CustomerAccount::query()->count())->toBe(0);
    });
});

it('refuses a walk-in with no name to put on it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        expect(fn (): ServiceJourney => wiCreate($seed, $owner, ['name' => null]))
            ->toThrow(JourneyFailed::class, 'needs a name');
    });
});

it('requires a real branch and at least one service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        expect(fn (): ServiceJourney => wiCreate($seed, $owner, ['branch' => 'not-a-branch']))
            ->toThrow(JourneyFailed::class, 'branch was not found');

        expect(fn (): ServiceJourney => wiCreate($seed, $owner, ['services' => []]))
            ->toThrow(JourneyFailed::class, 'at least one service');
    });
});

it('refuses an inactive service, and one from another branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        $retired = $this->seedService('Retired service', 30, 10000, $seed['employee']);
        $retired->forceFill(['is_active' => false])->save();

        expect(fn (): ServiceJourney => wiCreate($seed, $owner, ['services' => [$retired->uuid]]))
            ->toThrow(JourneyFailed::class, 'not available');

        // "Available at all branches" is the ABSENCE of pivot rows, so pinning
        // a service to some other branch takes it away from this one.
        $elsewhere = $this->seedService('Branch-only service', 30, 10000, $seed['employee']);
        $other = $this->seedBranch('Mansour');
        $elsewhere->forceFill(['available_at_all_branches' => false])->save();
        $elsewhere->branches()->sync([$other->id]);

        expect(fn (): ServiceJourney => wiCreate($seed, $owner, ['services' => [$elsewhere->uuid]]))
            ->toThrow(JourneyFailed::class, 'not offered at this branch');
    });
});

it('refuses a walk-in without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();

        $limited = $this->staffWith([Permission::JourneyView, Permission::JourneyManage], 'limited@alpha.test');

        expect(fn (): ServiceJourney => wiCreate($seed, $limited))
            ->toThrow(AuthorizationException::class);
    });
});

it('creates exactly one visit however many times the button is pressed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        $token = 'walk-in-'.bin2hex(random_bytes(8));

        $first = wiCreate($seed, $owner, ['token' => $token]);
        $second = wiCreate($seed, $owner, ['token' => $token]);
        $third = wiCreate($seed, $owner, ['token' => $token]);

        expect($first->uuid)->toBe($second->uuid)
            ->and($second->uuid)->toBe($third->uuid)
            ->and(ServiceJourney::query()->count())->toBe(1)
            // No duplicate stages and no duplicate customer either.
            ->and(JourneyStage::query()->count())->toBe(1)
            ->and(Customer::query()->count())->toBe(1);
    });
});

it('holds a room for a walk-in under the same capacity rules', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        $journey = wiCreate($seed, $owner);

        /** @var JourneyStage $stage */
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        $usage = $stage->resources()->first();

        // Allocated at START, not reserved in advance — a walk-in reserved
        // nothing — but through the SAME allocator the Booking Engine uses.
        expect($usage)->toBeInstanceOf(JourneyStageResource::class)
            ->and($usage?->resource_id)->toBe($seed['room']->id)
            ->and($usage?->released_at)->toBeNull();

        // And the exclusive room is now genuinely taken.
        $second = wiCreate($seed, $owner, ['name' => 'Second walk-in']);

        /** @var JourneyStage $secondStage */
        $secondStage = $second->stages()->first();

        expect(fn (): JourneyStage => app(TransitionStage::class)($secondStage, StageStatus::InService, $owner))
            ->toThrow(JourneyFailed::class, 'already in use');
    });
});

it('never lets a walk-in take capacity a booking has already committed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        // Somebody has this room booked from 10:30.
        app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                startsAt: $this->localTime($seed['branch'], wiDate(), '10:30'),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                customer: CustomerRef::details('Booked customer', '+96475'.random_int(10000000, 99999999)),
            ),
            BookingActor::staff($owner),
        )->appointment;

        $journey = wiCreate($seed, $owner, ['name' => 'Early bird']);

        /** @var JourneyStage $stage */
        $stage = $journey->stages()->first();

        /*
         * THE PHASE 7 HARDENING, inherited by a caller it was not written for.
         * 10:10 plus the service's thirty minutes runs to 10:40, straight
         * through the booking's committed half hour (ADR-050).
         */
        expect(fn (): JourneyStage => app(TransitionStage::class)(
            $stage,
            StageStatus::InService,
            $owner,
            [],
            $this->localTime($seed['branch'], wiDate(), '10:10'),
        ))->toThrow(JourneyFailed::class, 'reserved for another booking');

        expect(JourneyStageResource::query()->count())->toBe(0);
    });
});

it('leaves the booked journey path exactly as it was', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = wiSeed();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                startsAt: $this->localTime($seed['branch'], wiDate(), '11:00'),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                customer: CustomerRef::details('Booked customer', '+96475'.random_int(10000000, 99999999)),
            ),
            BookingActor::staff($owner),
        )->appointment;

        $journey = app(CheckInAppointment::class)($appointment, $owner);

        /** @var JourneyStage $stage */
        $stage = $journey->stages()->first();

        expect($journey->source)->toBe(JourneySource::Appointment)
            // The booked half stays where it was: the journey does NOT copy the
            // customer or the branch, it reads them through the appointment.
            ->and($journey->customer_id)->toBeNull()
            ->and($journey->branch_id)->toBeNull()
            ->and($journey->branchId())->toBe($seed['branch']->id)
            ->and($journey->customerId())->toBe((int) $appointment->customer_id)
            // And the stage still points at its booked item, with no snapshot.
            ->and($stage->appointment_item_id)->not->toBeNull()
            ->and($stage->service_id)->toBeNull()
            ->and($stage->isWalkIn())->toBeFalse()
            ->and($stage->durationMinutes())->toBe(30);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
