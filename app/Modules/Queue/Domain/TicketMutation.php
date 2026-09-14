<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain;

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Domain\Data\TicketChange;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\Models\QueueTicketEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * THE only thing that writes a queue ticket.
 *
 * ## Seven steps, once
 *
 *   1. open a transaction
 *   2. re-read the ticket `FOR UPDATE`
 *   3. validate against the LOCKED row, never the one the caller was holding
 *   4. allocate the next history sequence under that same lock
 *   5. write the ticket
 *   6. append exactly one history row
 *   7. commit
 *
 * Two desks pressing "call" on A012 at the same instant is not a hypothetical:
 * it is a Saturday. Without the lock, both would read `waiting`, both would
 * decide the move was legal, both would write `called`, and both would compute
 * history sequence 2 — one of which the unique index would reject, taking an
 * otherwise-successful call down with it (docs/17-QUEUE.md §5, correction 5).
 *
 * With it, the second desk waits, re-reads `called`, and gets a refusal that
 * names what actually happened.
 *
 * ## Validate the locked row, not the model you came in with
 *
 * The `$decide` callback receives the freshly locked ticket. An Action that
 * checked `$ticket->state` before the transaction would be checking a value
 * that was true when the page rendered.
 *
 * ## Exactly one history row
 *
 * Not zero — a mutation nobody can see afterwards is how "why was this customer
 * called four times" becomes unanswerable. Not two — a caller that wants two
 * facts recorded is describing two changes.
 */
final class TicketMutation
{
    /**
     * `$actor` is null for the SYNCHRONIZER, which reacts to a Journey fact
     * rather than to somebody pressing a button. The history row then records a
     * `system` actor, which is the honest answer — the person who started the
     * service is in the Journey audit entry, not here.
     *
     * @param  callable(QueueTicket): TicketChange  $decide  validates and says what to do
     *
     * @throws QueueFailed
     */
    public function apply(QueueTicket $ticket, ?User $actor, callable $decide, ?CarbonImmutable $now = null): QueueTicket
    {
        // UTC, explicitly, and DATETIME columns throughout: every queue instant
        // is one the product does arithmetic on (CLAUDE.md, ADR-046).
        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var QueueTicket $result */
        $result = DB::connection('tenant')->transaction(function () use ($ticket, $actor, $decide, $at): QueueTicket {
            $locked = QueueTicket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof QueueTicket) {
                throw QueueFailed::policy('That ticket no longer exists.');
            }

            $change = $decide($locked);

            $this->assertLegal($locked, $change);

            // Captured BEFORE the write: saving syncs the model's original
            // attributes, so reading it afterwards would record the new state
            // as the old one.
            $from = $locked->state->value;

            $event = $this->append($locked, $change, $actor, $at, $from);

            $attributes = $change->attributes + ['state' => $change->state];

            /*
             * A call or a recall becomes the announcement a screen is currently
             * speaking. Set here rather than by each Action, so a future call
             * path cannot forget it and leave a television repeating the
             * previous customer (correction 2).
             */
            if ($event->isAnnounceable()) {
                $attributes['last_announcement_uuid'] = $event->uuid;
            }

            $locked->forceFill($attributes)->save();

            return $locked;
        });

        return $result;
    }

    /**
     * @throws QueueFailed
     */
    private function assertLegal(QueueTicket $ticket, TicketChange $change): void
    {
        if ($change->stateless) {
            // A change that does not move the ticket — a priority adjustment.
            // It still may not touch a finished one.
            if ($ticket->state->isTerminal()) {
                throw QueueFailed::invalidTransition(
                    "A ticket that is {$ticket->state->value} can no longer be changed.",
                    ['state' => $ticket->state->value],
                );
            }

            return;
        }

        if (! $ticket->state->canTransitionTo($change->state)) {
            // Names both ends: "a ticket that is completed cannot be called" is
            // actionable at a desk, and "invalid transition" is not.
            throw QueueFailed::invalidTransition(
                "A ticket that is {$ticket->state->value} cannot become {$change->state->value}.",
                ['from' => $ticket->state->value, 'to' => $change->state->value],
            );
        }
    }

    /**
     * The next sequence, computed while the ticket row is held.
     *
     * `MAX(sequence) + 1` is safe HERE and nowhere else: every writer locks the
     * ticket before reaching this line, so no two can be computing it for the
     * same ticket at once. The unique index on `(queue_ticket_id, sequence)` is
     * what says so even if a future path forgets.
     */
    private function append(
        QueueTicket $ticket,
        TicketChange $change,
        ?User $actor,
        CarbonImmutable $at,
        string $from,
    ): QueueTicketEvent {
        $sequence = 1 + (int) QueueTicketEvent::query()
            ->where('queue_ticket_id', $ticket->getKey())
            ->max('sequence');

        /** @var QueueTicketEvent $event */
        $event = QueueTicketEvent::query()->create([
            'queue_ticket_id' => $ticket->getKey(),
            'sequence' => $sequence,
            'type' => $change->event,
            'from_state' => $from,
            'to_state' => $change->state->value,
            // Recorded per event, so a transfer cannot erase where somebody was
            // sent the first time (§16).
            'service_point_id' => $change->servicePointId ?? $ticket->service_point_id,
            'department_id' => $change->departmentId ?? $ticket->department_id,
            'reason' => $change->reason,
            'actor_type' => $actor === null ? 'system' : 'staff',
            'actor_id' => $actor?->uuid,
            'actor_label' => $actor?->name,
            'occurred_at' => $at,
        ]);

        return $event;
    }
}
