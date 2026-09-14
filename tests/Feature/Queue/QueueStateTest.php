<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CancelTicket;
use App\Modules\Queue\Application\Actions\ChangeTicketPriority;
use App\Modules\Queue\Application\Actions\CompleteServingTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\StartServingTicket;
use App\Modules\Queue\Application\Actions\TransferTicket;
use App\Modules\Queue\Application\QueueBoardQuery;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;

/*
|--------------------------------------------------------------------------
| The queue state machine
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §10, corrections 3 and 5.
|
| A ticket is a WAITING AND CALLING mechanism. It is not the source of truth for
| whether a service happened — that is `JourneyStage.status`, and the two never
| disagree because only one of them decides.
|
*/

function qSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');
    $seed['room'] = test()->seedServicePoint($seed['branch'], 'L2', 'Laser Room 2', sortOrder: 1);

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

/**
 * A walk-in with a ticket — the shortest path to a live queue entry.
 */
function qTicket(array $seed, User $owner, string $name = 'Sara'): QueueTicket
{
    $result = app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
    );

    return $result['ticket'];
}

it('issues a waiting ticket with a readable number', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        expect($ticket->state)->toBe(TicketState::Waiting)
            ->and($ticket->display_number)->toBe('A001')
            ->and($ticket->number)->toBe(1)
            ->and($ticket->prefix)->toBe('A')
            // The invariant that stops a double-click issuing two numbers.
            ->and($ticket->active_journey_stage_id)->toBe($ticket->journey_stage_id)
            ->and($ticket->events()->count())->toBe(1)
            ->and($ticket->events()->first()?->type->value)->toBe('issued');
    });
});

it('walks a ticket from waiting through called to serving and completed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        expect($ticket->fresh()?->state)->toBe(TicketState::Called)
            ->and($ticket->fresh()?->service_point_id)->toBe($seed['reception']->id)
            ->and($ticket->fresh()?->call_count)->toBe(1);

        /*
         * Starting goes through JOURNEY. The ticket becomes `serving` because
         * the stage became `in_service`, not because the queue wrote it
         * (correction 3).
         */
        app(StartServingTicket::class)($ticket->fresh(), $owner);

        $ticket = $ticket->fresh();

        expect($ticket?->state)->toBe(TicketState::Serving)
            ->and($ticket?->serving_started_at)->not->toBeNull()
            ->and($ticket?->stage?->status)->toBe(StageStatus::InService);

        app(CompleteServingTicket::class)($ticket, $owner);

        $ticket = $ticket->fresh();

        expect($ticket?->state)->toBe(TicketState::Completed)
            ->and($ticket?->closed_at)->not->toBeNull()
            // RELEASED, so the stage could be ticketed again if it ever needed
            // to be — the index allows one OPEN ticket, not one ever.
            ->and($ticket?->active_journey_stage_id)->toBeNull()
            ->and($ticket?->stage?->status)->toBe(StageStatus::Completed);
    });
});

it('recalls without losing the first call', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);
        $first = $ticket->fresh();

        app(CallTicket::class)($first, $owner, $seed['reception']->uuid);
        $second = $ticket->fresh();

        expect($second?->state)->toBe(TicketState::Called)
            ->and($second?->call_count)->toBe(2)
            // The FIRST call is kept for good: every waiting-time figure is
            // measured to it.
            ->and($second?->first_called_at?->timestamp)->toBe($first?->first_called_at?->timestamp)
            // And a recall is a NEW announcement, which is what makes the
            // television speak again (correction 2).
            ->and($second?->last_announcement_uuid)->not->toBe($first?->last_announcement_uuid);
    });
});

it('holds and resumes without losing the customer their place', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $first = qTicket($seed, $owner, 'First');
        $second = qTicket($seed, $owner, 'Second');

        app(HoldTicket::class)($first, $owner, false, 'Gone to the pharmacy');

        expect($first->fresh()?->state)->toBe(TicketState::Held)
            ->and($first->fresh()?->hold_reason)->toBe('Gone to the pharmacy');

        app(ResumeTicket::class)($first->fresh(), $owner);

        $resumed = $first->fresh();

        expect($resumed?->state)->toBe(TicketState::Waiting)
            ->and($resumed?->held_at)->toBeNull()
            // ORIGINAL issued_at, so they return to where they were rather than
            // to the back of a Saturday queue (§14, §20).
            ->and($resumed?->issued_at->timestamp)->toBe($first->issued_at->timestamp)
            /*
             * `lessThanOrEqualTo`, not `lessThan`: DATETIME has one-second
             * resolution, and two walk-ins taken in the same second share an
             * `issued_at`. That is exactly why the call order ends in `id ASC`
             * — the tie is settled by something deterministic rather than by
             * whatever the engine returns first (§20).
             */
            ->and($resumed?->issued_at->lessThanOrEqualTo($second->issued_at))->toBeTrue()
            ->and($resumed?->id)->toBeLessThan($second->id);

        // And that is what the call order actually does with them.
        $next = app(QueueBoardQuery::class)->nextToCall($seed['branch']);

        expect($next?->uuid)->toBe($first->uuid);
    });
});

it('treats a skip as a recoverable hold, never as a declined service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner);
        app(HoldTicket::class)($ticket->fresh(), $owner, true);

        $skipped = $ticket->fresh();

        expect($skipped?->state)->toBe(TicketState::Held)
            ->and($skipped?->skip_count)->toBe(1)
            ->and($skipped?->hold_reason)->toBe('No response')
            /*
             * THE DISTINCTION THAT MATTERS. `StageStatus::Skipped` means the
             * customer DECLINED the service. Missing a queue call means nothing
             * of the kind, and the stage must be untouched (§15).
             */
            ->and($skipped?->stage?->status)->toBe(StageStatus::Waiting);

        // And they are recoverable, which is the whole point.
        app(ResumeTicket::class)($skipped, $owner);

        expect($ticket->fresh()?->state)->toBe(TicketState::Waiting);
    });
});

it('refuses to skip a ticket nobody has called', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        expect(fn (): QueueTicket => app(HoldTicket::class)($ticket, $owner, true))
            ->toThrow(QueueFailed::class, 'has been called can be skipped');
    });
});

it('transfers to a new destination and asks for another call', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        app(TransferTicket::class)($ticket->fresh(), $owner, $seed['room']->uuid, null, 'Room freed up');

        $transferred = $ticket->fresh();

        expect($transferred?->service_point_id)->toBe($seed['room']->id)
            /*
             * Back to WAITING. A transferred ticket still showing `called`
             * would be a customer standing at a counter nobody expects them at
             * (§16).
             */
            ->and($transferred?->state)->toBe(TicketState::Waiting)
            // The earlier call is untouched.
            ->and($transferred?->call_count)->toBe(1);
    });
});

it('refuses every operator move once the service has started', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner);
        app(StartServingTicket::class)($ticket->fresh(), $owner);

        $serving = $ticket->fresh();

        /*
         * Holding, transferring or cancelling a ticket whose stage is
         * `in_service` would make the two domains disagree about a customer
         * sitting in a chair. Somebody who walks out mid-service is an
         * ABANDONED VISIT, and every refusal says so (correction 3).
         */
        expect(fn (): QueueTicket => app(HoldTicket::class)($serving, $owner))
            ->toThrow(QueueFailed::class, 'already being served');

        expect(fn (): QueueTicket => app(CancelTicket::class)($serving, $owner))
            ->toThrow(QueueFailed::class, 'End the visit instead');

        expect(fn (): QueueTicket => app(TransferTicket::class)($serving, $owner, $seed['room']->uuid))
            ->toThrow(QueueFailed::class, 'Hand the work on instead');

        expect($ticket->fresh()?->state)->toBe(TicketState::Serving)
            ->and($ticket->fresh()?->stage?->status)->toBe(StageStatus::InService);
    });
});

it('refuses to start a service that is not waiting, and to finish one that has not started', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        expect(fn (): QueueTicket => app(CompleteServingTicket::class)($ticket, $owner))
            ->toThrow(QueueFailed::class, 'cannot be finished');

        app(StartServingTicket::class)($ticket, $owner);

        expect(fn (): QueueTicket => app(StartServingTicket::class)($ticket->fresh(), $owner))
            ->toThrow(QueueFailed::class, 'already in_service');
    });
});

it('cancels a waiting ticket without touching the visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CancelTicket::class)($ticket, $owner, 'Wrong service chosen');

        $cancelled = $ticket->fresh();

        expect($cancelled?->state)->toBe(TicketState::Cancelled)
            ->and($cancelled?->close_reason)->toBe('Wrong service chosen')
            ->and($cancelled?->active_journey_stage_id)->toBeNull()
            // The VISIT is untouched: only the number was called off.
            ->and($cancelled?->stage?->status)->toBe(StageStatus::Waiting)
            ->and($cancelled?->journey?->status->value)->toBe('active');

        // And because the invariant released, the stage can be ticketed again.
        /** @var JourneyStage $stage */
        $stage = $cancelled?->stage;

        $reissued = app(IssueTicket::class)($stage, $owner);

        expect($reissued->display_number)->toBe('A002')
            ->and($reissued->state)->toBe(TicketState::Waiting);
    });
});

it('refuses a move that is not on the map', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qTicket($seed, $owner);

        app(CancelTicket::class)($ticket, $owner);

        // Names both ends, because "a ticket that is cancelled cannot become
        // called" is actionable at a desk and "invalid transition" is not.
        expect(fn (): QueueTicket => app(CallTicket::class)($ticket->fresh(), $owner))
            ->toThrow(QueueFailed::class, 'cancelled cannot become called');

        expect(fn (): QueueTicket => app(ChangeTicketPriority::class)($ticket->fresh(), 10, $owner))
            ->toThrow(QueueFailed::class, 'can no longer be changed');
    });
});

it('orders the queue by priority, then arrival, then id', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qSeed();
        $owner = $this->ownerWithCatalogAccess();

        $first = qTicket($seed, $owner, 'First');
        $second = qTicket($seed, $owner, 'Second');
        $third = qTicket($seed, $owner, 'Third');

        // Straight FIFO to begin with.
        expect(app(QueueBoardQuery::class)->nextToCall($seed['branch'])?->uuid)->toBe($first->uuid);

        app(ChangeTicketPriority::class)($third, 20, $owner, 'Elderly customer');

        // Priority leads, because it is what changes who is next.
        expect(app(QueueBoardQuery::class)->nextToCall($seed['branch'])?->uuid)->toBe($third->uuid)
            ->and($third->fresh()?->priority)->toBe(20);

        unset($second);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
