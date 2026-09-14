<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\SwapStageResource;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Runtime resource capacity: actual occupancy AND committed reservations
|--------------------------------------------------------------------------
|
| docs/16-JOURNEY-RESOURCES.md §15, ADR-050.
|
| Phase 7 shipped with the actual-capacity check looking ONLY at other stages'
| live holds, and recorded the gap as a risk:
|
|     Capacity 1. The room is reserved 10:30 → 11:00 for another customer.
|     Somebody arrives early and a stage is started at 10:10, expected to run
|     until 10:40. There is no live hold at 10:10 — so it was admitted, and
|     the 10:30 customer arrived to find their room occupied.
|
| Runtime capacity is BOTH: what is physically in use, plus what is promised.
| One combined peak-occupancy calculation, counting neither customer twice.
|
*/

function jrcDate(): string
{
    return CarbonImmutable::now()->addDays(27)->format('Y-m-d');
}

/**
 * A UTC instant for a branch-local wall-clock time on the test's date.
 *
 * Journey Actions take `now` as an argument, so "the customer walked in at
 * 10:10" is a value rather than a clock the test has to travel.
 */
function jrcAt(array $seed, string $time): CarbonImmutable
{
    return test()->localTime($seed['branch'], jrcDate(), $time);
}

/**
 * A branch with rooms the seeded 30-minute service requires.
 */
function jrcSeed(int $capacity = 1, int $rooms = 1): array
{
    $seed = test()->seedBookableCenter();
    $type = test()->seedResourceType('Treatment Room');

    $seed['type'] = $type;
    $seed['rooms'] = [];

    for ($i = 1; $i <= $rooms; $i++) {
        $seed['rooms'][] = test()->seedResource($type, $seed['branch'], "Room {$i}", $capacity, null, $i);
    }

    $seed['room'] = $seed['rooms'][0];

    test()->requireResource($seed['service'], $type);

    // Six stylists, so it is never the EMPLOYEE that refuses a booking or a
    // start here. Every refusal in this file has to be about the room.
    for ($i = 2; $i <= 6; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

/**
 * @param  list<string>  $resources
 */
function jrcBook(array $seed, string $time, array $resources = []): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: jrcAt($seed, $time),
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid, resourceUuids: $resources)],
            customer: CustomerRef::details('Sara', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

function jrcStage(Appointment $appointment, User $owner): JourneyStage
{
    $journey = app(CheckInAppointment::class)($appointment, $owner);

    /** @var JourneyStage $stage */
    $stage = $journey->stages()->first();

    return $stage;
}

function jrcStart(JourneyStage $stage, CarbonImmutable $at, User $owner): JourneyStage
{
    return app(TransitionStage::class)($stage, StageStatus::InService, $owner, [], $at);
}

/**
 * Queries issued while $work runs.
 *
 * Named locally rather than borrowed from another test file: a helper that only
 * exists when some other file happens to be loaded makes running one file on
 * its own fail for a reason that has nothing to do with the code.
 */
function jrcQueryCount(callable $work): int
{
    $count = 0;

    Event::listen(QueryExecuted::class, function () use (&$count): void {
        $count++;
    });

    $work();

    Event::forget(QueryExecuted::class);

    return $count;
}

it('refuses the exact collision the Phase 7 risk report described', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        // THE REGRESSION. Another customer holds the room 10:30 → 11:00.
        $blocker = jrcBook($seed, '10:30');

        // Ours is booked for 11:00; the customer turns up at 10:10 and a host
        // presses start. Expected use runs to 10:40, straight through somebody
        // else's committed half hour.
        $stage = jrcStage(jrcBook($seed, '11:00'), $owner);

        expect(fn (): JourneyStage => jrcStart($stage, jrcAt($seed, '10:10'), $owner))
            ->toThrow(JourneyFailed::class, 'reserved for another booking');

        expect($stage->fresh()?->status)->toBe(StageStatus::Waiting)
            // Nothing was opened. A refusal that had already written the usage
            // row would be the same bug wearing an exception.
            ->and(JourneyStageResource::query()->count())->toBe(0)
            // And the other booking is untouched. Committed capacity is never
            // taken away because the room is convenient now — the answer is a
            // different resource, or a refusal.
            ->and($blocker->fresh()?->status)->toBe(AppointmentStatus::Booked)
            ->and($blocker->items()->first()?->resourceReservations()->count())->toBe(1);
    });
});

it('allows a start whose expected use finishes before the next reservation', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        // Somebody else has the room from 11:00.
        jrcBook($seed, '11:00');

        $stage = jrcStage(jrcBook($seed, '10:00'), $owner);

        // 10:20 plus the booked thirty minutes is 10:50, which is done before
        // 11:00. The check is an admission window, not a curfew.
        jrcStart($stage, jrcAt($seed, '10:20'), $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::InService)
            ->and($stage->resources()->whereNull('released_at')->count())->toBe(1);
    });
});

it('refuses an early start whose expected use overlaps another reservation', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        jrcBook($seed, '10:30');

        $stage = jrcStage(jrcBook($seed, '11:00'), $owner);

        // No live hold at 10:10 — and that is exactly why the old check let
        // this through.
        expect(fn (): JourneyStage => jrcStart($stage, jrcAt($seed, '10:10'), $owner))
            ->toThrow(JourneyFailed::class, 'reserved for another booking');
    });
});

it('counts actual use and reservations together, up to capacity', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        // A shared room that takes three at once.
        $seed = jrcSeed(capacity: 3);
        $owner = $this->ownerWithCatalogAccess();

        $first = jrcBook($seed, '10:00');
        $second = jrcBook($seed, '10:00');
        jrcBook($seed, '10:00');

        $stageOne = jrcStage($first, $owner);
        $stageTwo = jrcStage($second, $owner);

        // Nobody is in the room yet: 0 actual + 2 other reservations + this
        // one = 3, which is exactly capacity.
        jrcStart($stageOne, jrcAt($seed, '10:00'), $owner);

        /*
         * And now the part a naive merge gets wrong. The first visit is
         * counted ONCE — as a live hold — not once as a hold and again as the
         * reservation it came from. Double counting would make this 4 and
         * refuse a customer the booking engine promised a place to.
         */
        jrcStart($stageTwo, jrcAt($seed, '10:00'), $owner);

        expect($stageOne->fresh()?->status)->toBe(StageStatus::InService)
            ->and($stageTwo->fresh()?->status)->toBe(StageStatus::InService)
            ->and(JourneyStageResource::query()->whereNull('released_at')->count())->toBe(2);
    });
});

it('refuses when actual use and reservations together exceed capacity', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 2);
        $owner = $this->ownerWithCatalogAccess();

        $inRoom = jrcBook($seed, '10:00');   // 10:00 → 10:30
        jrcBook($seed, '10:15');             // 10:15 → 10:45, still only booked
        $early = jrcBook($seed, '11:00');    // ours, and the customer is early

        jrcStart(jrcStage($inRoom, $owner), jrcAt($seed, '10:00'), $owner);

        /*
         * Window 10:20 → 10:50. One live hold, one committed reservation, and
         * capacity 2. NEITHER SET ALONE REFUSES THIS — one plus the candidate
         * is two either way. Only the combined peak reaches three.
         */
        $stage = jrcStage($early, $owner);

        expect(fn (): JourneyStage => jrcStart($stage, jrcAt($seed, '10:20'), $owner))
            ->toThrow(JourneyFailed::class, 'reserved for another booking');
    });
});

it('does not count the visit\'s own reservation against itself', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        // One exclusive room, one booking in it, starting exactly on time. The
        // candidate window is precisely the reservation's own window: counting
        // it would have the customer competing with themselves and nobody
        // would ever be able to start anything.
        $stage = jrcStage(jrcBook($seed, '10:00'), $owner);

        jrcStart($stage, jrcAt($seed, '10:00'), $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::InService)
            ->and($stage->resources()->whereNull('released_at')->count())->toBe(1);
    });
});

it('still counts another item of the same visit when it genuinely competes', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        $second = $this->seedService('Scalp treatment', 30, 30000, $seed['employee']);
        $this->requireResource($second, $seed['type']);

        // One visit, two services, back to back in the one exclusive room:
        // 10:00 → 10:30 and 10:30 → 11:00.
        $appointment = app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                startsAt: jrcAt($seed, '10:00'),
                lines: [
                    new BookingLine(serviceUuid: $seed['service']->uuid),
                    new BookingLine(serviceUuid: $second->uuid),
                ],
                customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
            ),
            BookingActor::staff($owner),
        );

        $stage = jrcStage($appointment, $owner);

        /*
         * Only the item being started is excluded — not the whole visit. Start
         * the haircut twenty minutes late and its expected half hour runs to
         * 10:50, over the scalp treatment's own committed half hour. That is a
         * real collision for a room that holds one.
         */
        expect(fn (): JourneyStage => jrcStart($stage, jrcAt($seed, '10:20'), $owner))
            ->toThrow(JourneyFailed::class, 'reserved for another booking');

        // On time, it fits, which is the point: the second item is a competitor
        // rather than a blanket veto.
        jrcStart($stage->fresh() ?? $stage, jrcAt($seed, '10:00'), $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::InService);
    });
});

it('stops counting a reservation once its appointment is cancelled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        $blocker = jrcBook($seed, '10:30');
        $stage = jrcStage(jrcBook($seed, '11:00'), $owner);

        expect(fn (): JourneyStage => jrcStart($stage, jrcAt($seed, '10:10'), $owner))
            ->toThrow(JourneyFailed::class);

        app(BookingEngine::class)->cancel($blocker, BookingActor::staff($owner), 'Customer called');

        /*
         * The SAME call, and the opposite answer. Only the appointment's status
         * changed: the reservation row is still sitting there, and it must stop
         * consuming capacity the moment the booking stops occupying the
         * calendar. Which statuses those are is Booking's rule, read through
         * the seam rather than restated here.
         */
        jrcStart($stage->fresh() ?? $stage, jrcAt($seed, '10:10'), $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::InService);
    });
});

it('stops counting reservations of completed and no-show appointments', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        $done = jrcBook($seed, '10:30');     // 10:30 → 11:00
        $absent = jrcBook($seed, '12:00');   // 12:00 → 12:30

        $ours = jrcBook($seed, '14:00');
        $later = jrcBook($seed, '15:00');

        app(BookingEngine::class)->transition($done, AppointmentStatus::Completed, BookingActor::staff($owner));

        // No-show needs a clock past the appointment's start, which is what the
        // Action's own rule says. Called directly so the test can supply one.
        app(TransitionAppointment::class)(
            $absent,
            AppointmentStatus::NoShow,
            BookingActor::staff($owner),
            [],
            jrcAt($seed, '12:40'),
        );

        // Over the completed booking's window.
        $stage = jrcStage($ours, $owner);
        jrcStart($stage, jrcAt($seed, '10:10'), $owner);
        app(TransitionStage::class)($stage->fresh() ?? $stage, StageStatus::Completed, $owner, [], jrcAt($seed, '10:40'));

        // Over the no-show's window, with the earlier hold released.
        $second = jrcStage($later, $owner);
        jrcStart($second, jrcAt($seed, '11:45'), $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::Completed)
            ->and($second->fresh()?->status)->toBe(StageStatus::InService);
    });
});

it('counts reservations of booked and confirmed appointments', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        $blocker = jrcBook($seed, '10:30');
        $stage = jrcStage(jrcBook($seed, '11:00'), $owner);

        expect(fn (): JourneyStage => jrcStart($stage, jrcAt($seed, '10:10'), $owner))
            ->toThrow(JourneyFailed::class, 'reserved for another booking');

        app(BookingEngine::class)->transition($blocker, AppointmentStatus::Confirmed, BookingActor::staff($owner));

        expect(fn (): JourneyStage => jrcStart($stage->fresh() ?? $stage, jrcAt($seed, '10:10'), $owner))
            ->toThrow(JourneyFailed::class, 'reserved for another booking')
            ->and($blocker->fresh()?->status)->toBe(AppointmentStatus::Confirmed);
    });
});

it('refuses a swap into a resource another booking has reserved', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1, rooms: 2);
        $owner = $this->ownerWithCatalogAccess();

        [$roomOne, $roomTwo] = $seed['rooms'];

        $ours = jrcBook($seed, '10:00', [$roomOne->uuid]);
        jrcBook($seed, '10:30', [$roomTwo->uuid]);

        $stage = jrcStage($ours, $owner);
        jrcStart($stage, jrcAt($seed, '10:10'), $owner);

        /*
         * Machine A has failed at 10:20 and the obvious move is Machine B. The
         * remaining expected use is 10:20 → 10:40 — the booked thirty minutes
         * measured from when the service actually started, not a fresh thirty —
         * and Machine B is promised to somebody at 10:30.
         */
        expect(fn (): JourneyStageResource => app(SwapStageResource::class)(
            $stage->fresh() ?? $stage,
            $roomOne->uuid,
            $roomTwo->uuid,
            $owner,
            'Air conditioning failed',
            jrcAt($seed, '10:20'),
        ))->toThrow(JourneyFailed::class, 'reserved for another booking');
    });
});

it('leaves the old resource usage open and unchanged when a swap is refused', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1, rooms: 2);
        $owner = $this->ownerWithCatalogAccess();

        [$roomOne, $roomTwo] = $seed['rooms'];

        $ours = jrcBook($seed, '10:00', [$roomOne->uuid]);
        jrcBook($seed, '10:30', [$roomTwo->uuid]);

        $stage = jrcStage($ours, $owner);
        jrcStart($stage, jrcAt($seed, '10:10'), $owner);

        $before = $stage->resources()->first();

        try {
            app(SwapStageResource::class)(
                $stage->fresh() ?? $stage,
                $roomOne->uuid,
                $roomTwo->uuid,
                $owner,
                'Air conditioning failed',
                jrcAt($seed, '10:20'),
            );
        } catch (JourneyFailed) {
            // Expected. What matters is the state it left behind.
        }

        $usages = $stage->resources()->get();

        /*
         * The customer never moved, so the record must not say they did. A swap
         * that closed the old row before proving the new one would leave them
         * physically in a room the system believes is empty — which is worse
         * than the refusal it was trying to avoid.
         */
        expect($usages)->toHaveCount(1)
            ->and($usages[0]->resource_id)->toBe($roomOne->id)
            ->and($usages[0]->released_at)->toBeNull()
            ->and($usages[0]->release_reason)->toBeNull()
            ->and($usages[0]->assigned_at?->timestamp)->toBe($before?->assigned_at?->timestamp);
    });
});

it('closes the old usage and opens the new one when a swap fits', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1, rooms: 2);
        $owner = $this->ownerWithCatalogAccess();

        [$roomOne, $roomTwo] = $seed['rooms'];

        $ours = jrcBook($seed, '10:00', [$roomOne->uuid]);

        // Room 2 is spoken for, but not until the afternoon.
        jrcBook($seed, '12:00', [$roomTwo->uuid]);

        $stage = jrcStage($ours, $owner);
        jrcStart($stage, jrcAt($seed, '10:10'), $owner);

        app(SwapStageResource::class)(
            $stage->fresh() ?? $stage,
            $roomOne->uuid,
            $roomTwo->uuid,
            $owner,
            'Air conditioning failed',
            jrcAt($seed, '10:20'),
        );

        $usages = $stage->resources()->get();

        expect($usages)->toHaveCount(2)
            ->and($usages[0]->resource_id)->toBe($roomOne->id)
            ->and($usages[0]->released_at?->timestamp)->toBe(jrcAt($seed, '10:20')->timestamp)
            ->and($usages[0]->release_reason)->toBe('Air conditioning failed')
            ->and($usages[1]->resource_id)->toBe($roomTwo->id)
            ->and($usages[1]->released_at)->toBeNull()
            // One instant, both sides of it. Validate, close, open — or none
            // of the three.
            ->and($usages[1]->assigned_at?->timestamp)->toBe($usages[0]->released_at?->timestamp);
    });
});

it('takes the branch lock around the combined capacity check', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        $stage = jrcStage(jrcBook($seed, '10:00'), $owner);

        $statements = [];

        DB::connection('tenant')->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        jrcStart($stage, jrcAt($seed, '10:00'), $owner);

        $locked = array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'branches')
                && str_contains($sql, 'for update'),
        );

        // The SAME row the Booking Engine locks. Reading committed reservations
        // without it would be reading a world that can change before the usage
        // row is written (ADR-047).
        expect($locked)->not->toBeEmpty();
    });
});

it('makes a stage start and a booking wait for each other', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);

        /** @var array<string, mixed> $config */
        $config = config('database.connections.tenant');

        // A genuinely separate connection. One PHP process cannot contend with
        // itself, so without this the "lock" could be a no-op and the test
        // would still pass.
        config(['database.connections.tenant_probe' => $config]);

        $probe = DB::connection('tenant_probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = null;

        DB::connection('tenant')->beginTransaction();

        try {
            // What TransitionStage does before it reads capacity.
            DB::connection('tenant')->table('branches')
                ->where('id', $seed['branch']->getKey())
                ->lockForUpdate()
                ->get();

            try {
                // And what CreateAppointment does first inside ITS transaction.
                $probe->table('branches')
                    ->where('id', $seed['branch']->getKey())
                    ->lockForUpdate()
                    ->get();

                $blocked = false;
            } catch (Throwable) {
                $blocked = true;
            }
        } finally {
            DB::connection('tenant')->rollBack();
            $probe->disconnect();
            DB::purge('tenant_probe');
        }

        expect($blocked)->toBeTrue();
    });
});

it('never lets one center\'s reservation consume another center\'s capacity', function (): void {
    /*
     * DISTINCT NAMES AND EMAILS. `registerCenter()` is idempotent on its
     * arguments — calling it twice with the defaults returns the SAME center,
     * and a cross-tenant test that did that would be quietly asserting nothing
     * about isolation at all.
     */
    $one = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $two = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $refused = $this->asCenter($one['tenant'], function (): bool {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        jrcBook($seed, '10:30');

        $stage = jrcStage(jrcBook($seed, '11:00'), $owner);

        try {
            jrcStart($stage, jrcAt($seed, '10:10'), $owner);

            return false;
        } catch (JourneyFailed) {
            return true;
        }
    });

    expect($refused)->toBeTrue();

    $this->asCenter($two['tenant'], function (): void {
        $seed = jrcSeed(capacity: 1);
        $owner = $this->ownerWithCatalogAccess();

        // The same start, at the same wall clock, in a center that has no such
        // reservation. Row ids collide across databases; the answer must not.
        $stage = jrcStage(jrcBook($seed, '11:00'), $owner);

        jrcStart($stage, jrcAt($seed, '10:10'), $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::InService);
    });
});

it('reads reservations in a fixed number of queries, however many there are', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = jrcSeed(capacity: 8);
        $owner = $this->ownerWithCatalogAccess();

        jrcBook($seed, '10:15');

        $first = jrcStage(jrcBook($seed, '11:00'), $owner);
        $few = jrcQueryCount(fn (): JourneyStage => jrcStart($first, jrcAt($seed, '10:10'), $owner));

        jrcBook($seed, '10:15');
        jrcBook($seed, '10:15');
        jrcBook($seed, '10:15');

        $second = jrcStage(jrcBook($seed, '12:00'), $owner);
        $many = jrcQueryCount(fn (): JourneyStage => jrcStart($second, jrcAt($seed, '10:10'), $owner));

        /*
         * Four times the competing reservations, the same number of round
         * trips: one bulk read of the reservations, one bulk read of which of
         * them have already started. A query per reservation would be the §40
         * mistake arriving in the operational path instead of the availability
         * one.
         */
        expect($many)->toBe($few)
            ->and($second->fresh()?->status)->toBe(StageStatus::InService);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
