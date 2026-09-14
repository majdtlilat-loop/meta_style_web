<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Queue\Application\Actions\AbandonQueuedVisit;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CompleteServingTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\Queue\Application\Actions\StartServingTicket;
use App\Modules\Queue\Application\SyncTicketsWithJourney;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageStarted;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Journey ↔ Queue consistency
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §12, correction 4.
|
| Journey is the source of truth for execution. A ticket must never say
| something its stage contradicts — and the two boards must produce identical
| results, because they go through the same Actions and the same events.
|
| The synchronizer is SYNCHRONOUS and runs inside the Journey transaction. A
| queued listener would make the two eventually consistent, and the intermediate
| state is precisely what a host and a television would both be showing.
|
*/

function qsSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qsTicket(array $seed, User $owner, string $name = 'Sara'): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
    )['ticket'];
}

it('is a synchronous listener, never a queued one', function (): void {
    /*
     * The whole guarantee rests on this. `ShouldQueue` here would mean a stage
     * could be `in_service` while its ticket still said `called` for as long as
     * a worker took to pick the job up — and on a center with no worker
     * running, forever (correction 4).
     */
    expect(is_subclass_of(SyncTicketsWithJourney::class, ShouldQueue::class))->toBeFalse();
});

it('moves the ticket when the JOURNEY board starts the stage', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qsTicket($seed, $owner);

        /** @var JourneyStage $stage */
        $stage = $ticket->stage;

        // Straight through Journey, with the queue never called at all.
        app(TransitionStage::class)($stage, StageStatus::InService, $owner);

        expect($ticket->fresh()?->state)->toBe(TicketState::Serving)
            ->and($ticket->fresh()?->serving_started_at)->not->toBeNull()
            ->and($ticket->fresh()?->stage?->status)->toBe(StageStatus::InService);

        app(TransitionStage::class)($stage->fresh(), StageStatus::Completed, $owner);

        expect($ticket->fresh()?->state)->toBe(TicketState::Completed)
            ->and($ticket->fresh()?->active_journey_stage_id)->toBeNull();
    });
});

it('produces the same result from the QUEUE board', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qsTicket($seed, $owner);

        app(StartServingTicket::class)($ticket, $owner);

        // Captured as a VALUE: the Action refreshes the model it was handed, so
        // holding the object and reading it later would report the end state
        // for both steps.
        $servingState = $ticket->fresh()?->state;

        app(CompleteServingTicket::class)($ticket->fresh(), $owner);
        $done = $ticket->fresh();

        // Identical to the Journey-board path above, because it IS that path:
        // the queue orchestration calls the same Action (§12).
        expect($servingState)->toBe(TicketState::Serving)
            ->and($done?->state)->toBe(TicketState::Completed)
            ->and($done?->stage?->status)->toBe(StageStatus::Completed)
            ->and($done?->active_journey_stage_id)->toBeNull()
            // And the history says how it got there, with a `system` actor for
            // the parts Journey decided.
            ->and($done?->events()->pluck('type')->map(fn ($t): string => $t->value)->all())
            ->toBe(['issued', 'serving_started', 'completed']);
    });
});

it('closes the ticket when a stage is skipped, without calling it a queue skip', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qsTicket($seed, $owner);

        /** @var JourneyStage $stage */
        $stage = $ticket->stage;

        // The customer DECLINED the service. That is a stage skip, and it ends
        // the work — quite unlike a queue skip, which means they did not answer
        // and are still waiting (§15).
        app(TransitionStage::class)($stage, StageStatus::Skipped, $owner, ['reason' => 'Changed their mind']);

        $closed = $ticket->fresh();

        expect($closed?->state)->toBe(TicketState::Completed)
            ->and($closed?->skip_count)->toBe(0)
            ->and($closed?->events()->where('type', 'completed')->first()?->reason)->toBe('skipped');
    });
});

it('cancels every open ticket when the visit is abandoned', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qsTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        app(AbandonQueuedVisit::class)($ticket->journey, $owner, 'Customer left');

        $closed = $ticket->fresh();

        expect($closed?->state)->toBe(TicketState::Cancelled)
            ->and($closed?->close_reason)->toBe('Visit abandoned')
            ->and($closed?->active_journey_stage_id)->toBeNull()
            // A number that is still on a television for somebody who has gone
            // home is the thing this prevents (§39).
            ->and($closed?->journey?->status->value)->toBe('aborted');
    });
});

it('abandons a BOOKED visit through the booking lifecycle, not around it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                startsAt: $this->localTime($seed['branch'], CarbonImmutable::now()->addDays(33)->format('Y-m-d'), '10:00'),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                customer: CustomerRef::details('Booked customer', '+96475'.random_int(10000000, 99999999)),
            ),
            BookingActor::staff($owner),
        );

        $journey = app(CheckInAppointment::class)($appointment, $owner);

        /** @var JourneyStage $stage */
        $stage = $journey->stages()->first();

        $ticket = app(IssueTicket::class)($stage, $owner);

        app(AbandonQueuedVisit::class)($journey, $owner, 'Left before being seen');

        expect($ticket->fresh()?->state)->toBe(TicketState::Cancelled)
            ->and($journey->fresh()?->status->value)->toBe('aborted')
            /*
             * The APPOINTMENT was cancelled by the Booking Action, not by a
             * write from the queue or from Journey. Queue calls one
             * orchestration and the consequences follow (§39, Phase 7 §28).
             */
            ->and($appointment->fresh()?->status)->toBe(AppointmentStatus::Cancelled)
            ->and($appointment->fresh()?->cancellation_reason)->toBe('Left before being seen');

        unset($engine);
    });
});

it('does nothing at all when the center issued no ticket', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        // A walk-in created through JOURNEY, with no queue involved. A center
        // without the queue entitlement works exactly like this, and the
        // listener must be a no-op rather than an error (§19).
        $journey = app(CreateWalkInVisit::class)(
            new WalkInRequest(
                branchUuid: $seed['branch']->uuid,
                serviceUuids: [$seed['service']->uuid],
                name: 'No ticket',
            ),
            $owner,
        );

        /** @var JourneyStage $stage */
        $stage = $journey->stages()->first();

        app(TransitionStage::class)($stage, StageStatus::InService, $owner);
        app(TransitionStage::class)($stage->fresh(), StageStatus::Completed, $owner);

        expect($stage->fresh()?->status)->toBe(StageStatus::Completed)
            ->and(QueueTicket::query()->count())->toBe(0);
    });
});

it('is idempotent when the same fact arrives twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qsTicket($seed, $owner);

        app(StartServingTicket::class)($ticket, $owner);

        $before = $ticket->fresh()?->events()->count();

        // Replaying the event — which is what a retry or a duplicate dispatch
        // would look like — must change nothing.
        app(SyncTicketsWithJourney::class)->handleStageStarted(new JourneyStageStarted(
            (int) $ticket->journey_stage_id,
            (int) $ticket->service_journey_id,
            (int) $ticket->branch_id,
        ));

        expect($ticket->fresh()?->state)->toBe(TicketState::Serving)
            ->and($ticket->fresh()?->events()->count())->toBe($before);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
