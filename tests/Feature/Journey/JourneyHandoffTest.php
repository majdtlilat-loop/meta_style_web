<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\HandoffStage;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\Actions\SwapStageResource;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyHandoff;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Handoff, reassignment and actual resource usage
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §§23-27, 53, and corrections §5.
|
| A customer moves through a center. Who performed what, in which room, and for
| how long — recorded as INTERVALS, so a mid-service swap stays readable.
|
*/

function hoDate(): string
{
    return CarbonImmutable::now()->addDays(25)->format('Y-m-d');
}

/**
 * Two services in two departments, each needing a room, with two rooms.
 */
function hoSeed(): array
{
    $seed = test()->seedBookableCenter();

    $hair = test()->seedDepartment('Hair');
    $laser = test()->seedDepartment('Laser');

    $seed['service']->forceFill(['department_id' => $hair->getKey()])->save();

    $second = test()->seedService('Laser session', 30, 50000, $seed['employee']);
    $second->forceFill(['department_id' => $laser->getKey()])->save();

    $type = test()->seedResourceType('Treatment Room');

    $seed['second'] = $second->fresh();
    $seed['hair'] = $hair;
    $seed['laser'] = $laser;
    $seed['type'] = $type;
    $seed['roomOne'] = test()->seedResource($type, $seed['branch'], 'Room 1', 1, null, 1);
    $seed['roomTwo'] = test()->seedResource($type, $seed['branch'], 'Room 2', 1, null, 2);

    test()->requireResource($seed['service'], $type);

    // Enough stylists that the EMPLOYEE constraint is never what refuses a
    // second booking — these tests are about rooms and handoffs.
    for ($i = 2; $i <= 4; $i++) {
        $extra = test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
        $second->eligibleEmployees()->syncWithoutDetaching([$extra->id]);
    }

    return $seed;
}

function hoBook(array $seed): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], hoDate(), '10:00'),
            lines: [
                new BookingLine(serviceUuid: $seed['service']->uuid),
                new BookingLine(serviceUuid: $seed['second']->uuid),
            ],
            customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

it('records both ends of a handoff, and moves the customer on', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);

        $stages = $journey->stages()->get();

        app(TransitionStage::class)($stages[0], StageStatus::InService, $owner);

        $handoff = app(HandoffStage::class)($stages[0]->fresh(), $owner, null, 'Going to the laser room');

        expect($handoff)->toBeInstanceOf(JourneyHandoff::class)
            ->and($handoff->from_stage_id)->toBe($stages[0]->id)
            ->and($handoff->to_stage_id)->toBe($stages[1]->id)
            // The destination's DEPARTMENT, which came from the booked service.
            ->and($handoff->from_department_id)->toBe($seed['hair']->id)
            ->and($handoff->to_department_id)->toBe($seed['laser']->id)
            ->and($handoff->note)->toBe('Going to the laser room')
            // Who recorded it.
            ->and($handoff->actor_label)->toBe($owner->name);

        // Handing the customer on finishes the service they were having.
        expect($stages[0]->fresh()?->status)->toBe(StageStatus::Completed);
    });
});

it('keeps every handoff, so the history of a visit is readable', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);

        $stages = $journey->stages()->get();

        app(TransitionStage::class)($stages[0], StageStatus::InService, $owner);
        app(HandoffStage::class)($stages[0]->fresh(), $owner);

        app(TransitionStage::class)($stages[1]->fresh(), StageStatus::InService, $owner);
        app(HandoffStage::class)($stages[1]->fresh(), $owner);

        // Append-only. A correction would be a new row, never an edit.
        expect($journey->handoffs()->count())->toBe(2);
    });
});

it('refuses a handoff to a stage in somebody else\'s visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();

        $mine = app(CheckInAppointment::class)(hoBook($seed), $owner);
        $theirs = app(CheckInAppointment::class)(hoBook($seed), $owner);

        $stage = $mine->stages()->first();
        $foreign = $theirs->stages()->first();

        expect(fn (): JourneyHandoff => app(HandoffStage::class)($stage, $owner, $foreign->uuid))
            ->toThrow(JourneyFailed::class, 'not a service in this visit');
    });
});

it('refuses a handoff without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);

        $limited = $this->staffWith(
            [Permission::JourneyView, Permission::JourneyStageStart],
            'limited@alpha.test',
        );

        expect(fn (): JourneyHandoff => app(HandoffStage::class)($journey->stages()->first(), $limited))
            ->toThrow(AuthorizationException::class);
    });
});

it('opens an actual resource hold when a service starts', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);
        $stage = $journey->stages()->first();

        expect($stage->resources()->count())->toBe(0);

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        $usage = $stage->resources()->first();

        expect($usage)->toBeInstanceOf(JourneyStageResource::class)
            ->and($usage?->resource_id)->toBe($seed['roomOne']->id)
            ->and($usage?->assigned_at)->not->toBeNull()
            ->and($usage?->released_at)->toBeNull();
    });
});

it('closes the old usage and opens a new one when a resource is swapped', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        $swapAt = CarbonImmutable::now()->addMinutes(15);

        app(SwapStageResource::class)(
            $stage->fresh(),
            $seed['roomOne']->uuid,
            $seed['roomTwo']->uuid,
            $owner,
            'Air conditioning failed',
            $swapAt,
        );

        $usages = $stage->resources()->get();

        /*
         * TWO ROWS, not one overwritten. Overwriting `resource_id` in place
         * would leave the record saying the customer was in Room 2 the whole
         * time — erasing the fifteen minutes Room 1 was in use and the fact
         * that something went wrong with it (corrections §5).
         */
        expect($usages)->toHaveCount(2)
            ->and($usages[0]->resource_id)->toBe($seed['roomOne']->id)
            ->and($usages[0]->released_at)->not->toBeNull()
            ->and($usages[0]->release_reason)->toBe('Air conditioning failed')
            ->and($usages[1]->resource_id)->toBe($seed['roomTwo']->id)
            ->and($usages[1]->released_at)->toBeNull()
            // The new hold starts exactly when the old one ended.
            ->and($usages[1]->assigned_at->timestamp)->toBe($usages[0]->released_at->timestamp);
    });
});

it('never rewrites the booked reservation when a resource is swapped', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = hoBook($seed);
        $journey = app(CheckInAppointment::class)($appointment, $owner);
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        app(SwapStageResource::class)(
            $stage->fresh(),
            $seed['roomOne']->uuid,
            $seed['roomTwo']->uuid,
            $owner,
        );

        // The BOOKING still says Room 1, because it did. Pretending otherwise
        // would hide the divergence this distinction exists to record (§26).
        $reservation = $appointment->items()->first()?->resourceReservations()->first();

        expect($reservation?->resource_id)->toBe($seed['roomOne']->id)
            ->and((string) $reservation?->resource_name)->toBe('Room 1');
    });
});

it('refuses to swap into a resource somebody else is using', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();

        // Two visits, each holding one of the two rooms.
        $first = app(CheckInAppointment::class)(hoBook($seed), $owner);
        $second = app(CheckInAppointment::class)(hoBook($seed), $owner);

        $stageA = $first->stages()->first();
        $stageB = $second->stages()->first();

        app(TransitionStage::class)($stageA, StageStatus::InService, $owner);

        // B is booked into Room 2, so starting it holds Room 2.
        app(TransitionStage::class)($stageB, StageStatus::InService, $owner);

        // A tries to move into the room B is physically occupying.
        expect(fn (): JourneyStageResource => app(SwapStageResource::class)(
            $stageA->fresh(),
            $seed['roomOne']->uuid,
            $seed['roomTwo']->uuid,
            $owner,
        ))->toThrow(JourneyFailed::class, 'already in use');
    });
});

it('releases every hold when a service finishes', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);
        app(TransitionStage::class)($stage->fresh(), StageStatus::Completed, $owner);

        expect($stage->resources()->whereNull('released_at')->count())->toBe(0)
            // Closed, NOT deleted: the interval it covered is the point.
            ->and($stage->resources()->count())->toBe(1);
    });
});

it('refuses to assign an employee from another tenant', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $betaEmployeeUuid = $this->asCenter($beta['tenant'], function (): string {
        $seed = $this->seedBookableCenter();

        return $seed['employee']->uuid;
    });

    $this->asCenter($alpha['tenant'], function () use ($betaEmployeeUuid): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);

        // The uuid is real — in another center's database. It resolves to
        // nothing here, which is exactly what tenant isolation means.
        expect(fn (): JourneyStage => app(ReassignStageEmployee::class)(
            $journey->stages()->first(),
            $betaEmployeeUuid,
            $owner,
        ))->toThrow(JourneyFailed::class, 'does not exist');
    });
});

it('refuses to swap into a resource from another tenant', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $betaResourceUuid = $this->asCenter($beta['tenant'], function (): string {
        $seed = $this->seedBookableCenter();
        $type = $this->seedResourceType();

        return $this->seedResource($type, $seed['branch'], 'Their room')->uuid;
    });

    $this->asCenter($alpha['tenant'], function () use ($betaResourceUuid): void {
        $seed = hoSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(hoBook($seed), $owner);
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        expect(fn (): JourneyStageResource => app(SwapStageResource::class)(
            $stage->fresh(),
            $seed['roomOne']->uuid,
            $betaResourceUuid,
            $owner,
        ))->toThrow(JourneyFailed::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
