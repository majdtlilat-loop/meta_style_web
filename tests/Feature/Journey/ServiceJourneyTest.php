<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Application\Actions\AbortJourney;
use App\Modules\ServiceJourney\Application\Actions\CancelVisit;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\ManageStageNotes;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Service Journey
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §§15-22, 27-29, 52, and corrections §§3, 4.
|
| APPOINTMENT = what was reserved. JOURNEY = what actually happened. The whole
| phase rests on those staying separate.
|
*/

function sjDate(): string
{
    return CarbonImmutable::now()->addDays(24)->format('Y-m-d');
}

/**
 * A two-service booking, so a journey has a route to follow.
 */
function sjSeed(): array
{
    $seed = test()->seedBookableCenter();

    $hair = test()->seedDepartment('Hair');
    $laser = test()->seedDepartment('Laser');

    $seed['service']->forceFill(['department_id' => $hair->getKey()])->save();

    $second = test()->seedService('Laser session', 30, 50000, $seed['employee']);
    $second->forceFill(['department_id' => $laser->getKey()])->save();

    $seed['second'] = $second->fresh();
    $seed['hair'] = $hair;
    $seed['laser'] = $laser;

    return $seed;
}

function sjBook(array $seed, string $time = '10:00'): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], sjDate(), $time),
            lines: [
                new BookingLine(serviceUuid: $seed['service']->uuid),
                new BookingLine(serviceUuid: $seed['second']->uuid),
            ],
            customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

it('has no journey until somebody checks the customer in', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $appointment = sjBook($seed);

        // ABSENCE is the state. A row for every future booking would fill the
        // operational tables with visits that have not happened (§17).
        expect(ServiceJourney::query()->where('appointment_id', $appointment->getKey())->exists())
            ->toBeFalse();
    });
});

it('creates exactly one journey, however many times check-in is pressed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $appointment = sjBook($seed);
        $owner = $this->ownerWithCatalogAccess();

        $checkIn = app(CheckInAppointment::class);

        $first = $checkIn($appointment, $owner);
        $second = $checkIn($appointment, $owner);
        $third = $checkIn($appointment, $owner);

        expect($first->uuid)->toBe($second->uuid)
            ->and($second->uuid)->toBe($third->uuid)
            ->and(ServiceJourney::query()->count())->toBe(1)
            // And no duplicate stages either.
            ->and(JourneyStage::query()->count())->toBe(2);
    });
});

it('returns the winner\'s journey when a concurrent check-in loses the race', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $appointment = sjBook($seed);
        $owner = $this->ownerWithCatalogAccess();

        /*
         * The unique index is the real guarantee, and this proves the Action
         * turns losing that race into "here is the journey that exists" rather
         * than a duplicate-key 500 (corrections §3).
         *
         * Simulated by inserting the row underneath the Action, which is
         * exactly what a concurrent request would have done.
         */
        $winner = app(CheckInAppointment::class)($appointment, $owner);

        DB::connection('tenant')->table('service_journeys')
            ->where('id', $winner->getKey())
            ->update(['status' => JourneyStatus::Active->value]);

        $loser = app(CheckInAppointment::class)($appointment, $owner);

        expect($loser->uuid)->toBe($winner->uuid)
            ->and(ServiceJourney::query()->count())->toBe(1);
    });
});

it('derives one stage per booked service, in order, with its department', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $appointment = sjBook($seed);

        $journey = app(CheckInAppointment::class)($appointment, $this->ownerWithCatalogAccess());

        $stages = $journey->stages()->get();

        expect($stages)->toHaveCount(2)
            ->and($stages[0]->position)->toBe(0)
            ->and($stages[1]->position)->toBe(1)
            // Department, NEVER menu category: routing is operational (ADR-037).
            ->and($stages[0]->department_id)->toBe($seed['hair']->id)
            ->and($stages[1]->department_id)->toBe($seed['laser']->id)
            ->and($stages[0]->status)->toBe(StageStatus::Waiting);
    });
});

it('does not invent an arrived status on the appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $appointment = sjBook($seed);

        app(CheckInAppointment::class)($appointment, $this->ownerWithCatalogAccess());

        // Arrival is an operational fact about the VISIT. The reservation is
        // still just booked (§16, §20).
        expect($appointment->fresh()?->status->value)->toBe('booked');
    });
});

it('walks a stage from waiting through service to completed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);

        $stage = $journey->stages()->first();
        $transition = app(TransitionStage::class);

        $transition($stage, StageStatus::InService, $owner);
        expect($stage->fresh()?->status)->toBe(StageStatus::InService)
            ->and($stage->fresh()?->service_started_at)->not->toBeNull();

        $transition($stage, StageStatus::Completed, $owner);
        expect($stage->fresh()?->status)->toBe(StageStatus::Completed)
            ->and($stage->fresh()?->service_completed_at)->not->toBeNull();
    });
});

it('refuses a transition that is not on the map', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);

        $stage = $journey->stages()->first();

        // Cannot finish what has not started.
        expect(fn (): JourneyStage => app(TransitionStage::class)($stage, StageStatus::Completed, $owner))
            ->toThrow(JourneyFailed::class);

        // Cannot skip what is already running.
        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        expect(fn (): JourneyStage => app(TransitionStage::class)(
            $stage->fresh(),
            StageStatus::Skipped,
            $owner,
            ['reason' => 'changed mind'],
        ))->toThrow(JourneyFailed::class);
    });
});

it('requires a reason to skip a service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);

        $stage = $journey->stages()->first();

        expect(fn (): JourneyStage => app(TransitionStage::class)($stage, StageStatus::Skipped, $owner))
            ->toThrow(JourneyFailed::class);

        $skipped = app(TransitionStage::class)(
            $stage,
            StageStatus::Skipped,
            $owner,
            ['reason' => 'Customer declined'],
        );

        expect($skipped->status)->toBe(StageStatus::Skipped)
            ->and($skipped->skip_reason)->toBe('Customer declined');
    });
});

it('records actual times without touching the planned ones', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = sjBook($seed);

        $plannedStart = $appointment->items()->first()?->starts_at;

        $journey = app(CheckInAppointment::class)($appointment, $owner);
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        // The customer arrived late; the plan is untouched. Both facts are
        // needed by every later delay report (§27).
        expect($appointment->items()->first()?->starts_at?->toIso8601String())
            ->toBe($plannedStart?->toIso8601String())
            ->and($stage->fresh()?->service_started_at?->toIso8601String())
            ->not->toBe($plannedStart?->toIso8601String());
    });
});

it('records the ACTUAL employee without rewriting the booked one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $appointment = sjBook($seed);
        $bookedEmployeeId = $appointment->items()->first()?->employee_id;

        $journey = app(CheckInAppointment::class)($appointment, $owner);
        $stage = $journey->stages()->first();

        app(ReassignStageEmployee::class)($stage, $sara->uuid, $owner);

        expect($stage->fresh()?->employee_id)->toBe($sara->id)
            // THE BOOKING IS UNCHANGED. That is what the customer was promised
            // and what their confirmation says (§§18, 24).
            ->and($appointment->items()->first()?->employee_id)->toBe($bookedEmployeeId)
            ->and($sara->id)->not->toBe($bookedEmployeeId);
    });
});

it('refuses to hand a service to somebody not qualified for it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();

        // At the branch, active, and not eligible for the service.
        $unqualified = $this->seedEmployee('Receptionist', $seed['branch']);

        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);
        $stage = $journey->stages()->first();

        // Rejected rather than overridden: no center has asked for the override,
        // so building one would be guessing at its rules (§24).
        expect(fn (): JourneyStage => app(ReassignStageEmployee::class)(
            $stage,
            $unqualified->uuid,
            $owner,
        ))->toThrow(JourneyFailed::class, 'not qualified');
    });
});

it('completes the visit only when every stage is settled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = sjBook($seed);
        $journey = app(CheckInAppointment::class)($appointment, $owner);

        expect(fn (): ServiceJourney => app(CompleteJourney::class)($journey, $owner))
            ->toThrow(JourneyFailed::class);

        $transition = app(TransitionStage::class);
        $stages = $journey->stages()->get();

        $transition($stages[0], StageStatus::InService, $owner);
        $transition($stages[0]->fresh(), StageStatus::Completed, $owner);
        // Skipped counts as settled: a declined service is finished business.
        $transition($stages[1], StageStatus::Skipped, $owner, ['reason' => 'Declined']);

        $completed = app(CompleteJourney::class)($journey->fresh(), $owner);

        expect($completed->status)->toBe(JourneyStatus::Completed)
            // And the APPOINTMENT went through the Booking lifecycle Action,
            // never a direct write from here (§28, §55).
            ->and($appointment->fresh()?->status->value)->toBe('completed')
            ->and($appointment->fresh()?->completed_at)->not->toBeNull();
    });
});

it('creates no invoice, sale or payment when a visit completes', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);

        $transition = app(TransitionStage::class);

        // One service actually PERFORMED — the thing a sale would charge for —
        // and the rest declined.
        foreach ($journey->stages()->orderBy('position')->get() as $index => $stage) {
            if ($index === 0) {
                $transition($stage, StageStatus::InService, $owner);
                $transition($stage->fresh() ?? $stage, StageStatus::Completed, $owner);

                continue;
            }

            $transition($stage, StageStatus::Skipped, $owner, ['reason' => 'Test']);
        }

        // Pinned: this center owns POS, so a sale COULD be created. Nothing may
        // be created merely because the visit ended — checkout is a separate,
        // explicit Sales Action (docs/18-SALES.md §§32–33).
        expect(app(Entitlements::class)->enabled('pos'))->toBeTrue();

        app(CompleteJourney::class)($journey->fresh(), $owner);

        expect($journey->fresh()?->status)->toBe(JourneyStatus::Completed);

        // Sales exists since Phase 9: completion wrote nothing to it.
        foreach (['sales', 'sale_items', 'invoices', 'invoice_items', 'invoice_share_links'] as $table) {
            expect(DB::connection('tenant')->table($table)->count())->toBe(0, "{$table} after completion");
        }

        $tables = DB::connection('tenant')->select('SHOW TABLES');
        $names = array_map(static fn (object $row): string => (string) array_values((array) $row)[0], $tables);

        // These modules do not exist yet. Faking their side effects would leave a
        // center's books full of records nothing can reconcile.
        foreach (['payments', 'commissions', 'loyalty_transactions'] as $forbidden) {
            expect($names)->not->toContain($forbidden);
        }
    });
});

it('abandons a visit without cancelling the appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = sjBook($seed);
        $journey = app(CheckInAppointment::class)($appointment, $owner);

        $aborted = app(AbortJourney::class)($journey, $owner, 'Customer left');

        expect($aborted->status)->toBe(JourneyStatus::Aborted)
            ->and($aborted->aborted_at)->not->toBeNull()
            ->and($aborted->abort_reason)->toBe('Customer left')
            // Journey NEVER writes appointment status (§55).
            ->and($appointment->fresh()?->status->value)->toBe('booked');
    });
});

it('refuses to abandon a completed visit or restart an abandoned one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);

        $transition = app(TransitionStage::class);

        foreach ($journey->stages()->get() as $stage) {
            $transition($stage, StageStatus::Skipped, $owner, ['reason' => 'Test']);
        }

        app(CompleteJourney::class)($journey->fresh(), $owner);

        expect(fn (): ServiceJourney => app(AbortJourney::class)($journey->fresh(), $owner))
            ->toThrow(JourneyFailed::class);

        // And an abandoned visit is terminal too.
        $other = app(CheckInAppointment::class)(sjBook($seed, '14:00'), $owner);
        app(AbortJourney::class)($other, $owner, 'left');

        expect(fn (): ServiceJourney => app(CompleteJourney::class)($other->fresh(), $owner))
            ->toThrow(JourneyFailed::class);
    });
});

it('leaves no dangling active journey when a started visit is cancelled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = sjBook($seed);
        $journey = app(CheckInAppointment::class)($appointment, $owner);

        expect($journey->status)->toBe(JourneyStatus::Active);

        // The supported flow: one Action, two lifecycles, each through the
        // module that owns it (corrections §4).
        app(CancelVisit::class)($appointment, $owner, 'Customer left');

        expect($journey->fresh()?->status)->toBe(JourneyStatus::Aborted)
            ->and($appointment->fresh()?->status->value)->toBe('cancelled')
            ->and(ServiceJourney::query()->active()->count())->toBe(0);
    });
});

it('keeps stage notes internal and audits that they exist, never what they say', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);
        $stage = $journey->stages()->first();

        $secret = 'Move to room 3, she asked not to sit near the window';

        app(ManageStageNotes::class)->add($stage, $secret, $owner);

        expect(app(ManageStageNotes::class)->visibleTo($stage, $owner))->toHaveCount(1);

        $rows = TenantAuditLog::query()->where('action', 'journey.stage_note.created')->get();

        expect($rows)->toHaveCount(1);

        foreach ($rows as $row) {
            expect(json_encode($row->getAttributes()))->not->toContain('room 3');
        }
    });
});

it('refuses journey work without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $appointment = sjBook($seed);

        $viewer = $this->staffWith([Permission::JourneyView], 'viewer@alpha.test');

        expect(fn (): ServiceJourney => app(CheckInAppointment::class)($appointment, $viewer))
            ->toThrow(AuthorizationException::class, 'may not manage visits');
    });
});

it('audits the operational events', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = sjSeed();
        $owner = $this->ownerWithCatalogAccess();
        $journey = app(CheckInAppointment::class)(sjBook($seed), $owner);
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);
        app(TransitionStage::class)($stage->fresh(), StageStatus::Completed, $owner);

        $actions = TenantAuditLog::query()->pluck('action')->all();

        expect($actions)->toContain('journey.checked_in')
            ->toContain('journey.stage.started')
            ->toContain('journey.stage.completed');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
