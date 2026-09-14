<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Modules\Queue\Domain\Data\TicketChange;
use App\Modules\Queue\Domain\Enums\TicketEventType;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\TicketMutation;
use App\Modules\ServiceJourney\Domain\Events\JourneyAborted;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageSettled;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageStarted;
use Carbon\CarbonImmutable;

/**
 * Keeps a queue ticket in step with the service it is waiting for.
 *
 * ## The rule it enforces
 *
 * Journey is the source of truth for execution. A ticket must never say
 * something the stage contradicts:
 *
 *     JourneyStage in_service  →  QueueTicket serving
 *     JourneyStage completed   →  QueueTicket completed
 *     JourneyStage skipped     →  QueueTicket completed
 *     ServiceJourney aborted   →  every open ticket cancelled
 *
 * ## Synchronous, and inside the same transaction
 *
 * This is a plain listener. It does NOT implement `ShouldQueue`, and it must
 * not: the events are dispatched inside the Journey Action's transaction, so
 * this work commits with it or not at all. A queued listener would make the two
 * domains eventually consistent, and the intermediate state — a stage
 * `in_service` beside a ticket still `called` — is exactly what the host and
 * the television would both be showing (docs/17-QUEUE.md §12, correction 4).
 *
 * A failure here therefore takes the Journey mutation down with it. That is the
 * intended trade: refusing to start a service is recoverable, and a ticket that
 * quietly disagrees with the floor is not.
 *
 * Realtime delivery is a different thing entirely and always secondary — the
 * database is the source of truth and a display re-reads it (§49).
 *
 * ## Idempotent
 *
 * Called twice for the same fact, the second call finds the ticket already in
 * the target state and does nothing. That matters because the Queue board's own
 * buttons go through the Journey Actions too, so both boards produce exactly
 * the same sequence of events.
 *
 * ## It writes only queue tables
 *
 * Never `journey_stages`, never `appointments`. The dependency points one way:
 * Queue listens to Journey, and Journey does not know this class exists.
 */
final class SyncTicketsWithJourney
{
    public function __construct(private readonly TicketMutation $mutation) {}

    public function handleStageStarted(JourneyStageStarted $event): void
    {
        $ticket = $this->openTicketForStage($event->stageId);

        if (! $ticket instanceof QueueTicket || $ticket->state === TicketState::Serving) {
            // No ticket at all is the normal case for a center that does not
            // use the queue; already serving is the idempotent one.
            return;
        }

        $at = CarbonImmutable::now()->utc();

        $this->mutation->apply(
            $ticket,
            null,
            fn (QueueTicket $locked): TicketChange => new TicketChange(
                state: TicketState::Serving,
                event: TicketEventType::ServingStarted,
                attributes: [
                    /*
                     * QUEUE METADATA, not the service's start time. The service
                     * began at `journey_stages.service_started_at`; this is when
                     * the queue learned about it, and a duration report reads
                     * the other one (§5).
                     */
                    'serving_started_at' => $at,
                    'held_at' => null,
                    'hold_reason' => null,
                ],
            ),
            $at,
        );
    }

    public function handleStageSettled(JourneyStageSettled $event): void
    {
        $ticket = $this->openTicketForStage($event->stageId);

        if (! $ticket instanceof QueueTicket) {
            return;
        }

        $at = CarbonImmutable::now()->utc();

        $this->mutation->apply(
            $ticket,
            null,
            fn (QueueTicket $locked): TicketChange => new TicketChange(
                state: TicketState::Completed,
                event: TicketEventType::Completed,
                attributes: [
                    'closed_at' => $at,
                    // Released, so the stage could be ticketed again if it ever
                    // needed to be. The unique index allows exactly one OPEN
                    // ticket, not exactly one ever (§11).
                    'active_journey_stage_id' => null,
                ],
                // Carries whether the service was performed or declined, which
                // the ticket itself has no opinion about.
                reason: $event->status,
            ),
            $at,
        );
    }

    public function handleJourneyAborted(JourneyAborted $event): void
    {
        $at = CarbonImmutable::now()->utc();

        $tickets = QueueTicket::query()
            ->where('service_journey_id', $event->journeyId)
            ->open()
            ->get();

        foreach ($tickets as $ticket) {
            /** @var QueueTicket $ticket */
            $this->mutation->apply(
                $ticket,
                null,
                fn (QueueTicket $locked): TicketChange => new TicketChange(
                    state: TicketState::Cancelled,
                    event: TicketEventType::Cancelled,
                    attributes: [
                        'closed_at' => $at,
                        'close_reason' => 'Visit abandoned',
                        'active_journey_stage_id' => null,
                    ],
                    reason: 'Visit abandoned',
                ),
                $at,
            );
        }
    }

    /**
     * The one open ticket for a stage, if the center issued one.
     *
     * Reads `active_journey_stage_id` rather than `journey_stage_id`: the
     * column that is unique, and the one that means "still open".
     */
    private function openTicketForStage(int $stageId): ?QueueTicket
    {
        return QueueTicket::query()
            ->where('active_journey_stage_id', $stageId)
            ->first();
    }
}
