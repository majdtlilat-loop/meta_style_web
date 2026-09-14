<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Enums;

/**
 * Where a queue ticket is in the waiting-and-calling cycle.
 *
 * ## NOT a service state
 *
 * This says nothing about whether a service is being performed. That is
 * `JourneyStage.status`, and it is the source of truth for execution — a ticket
 * is a mechanism for getting the customer to the right place, and the four
 * concepts stay apart on purpose (docs/17-QUEUE.md §1):
 *
 *     Appointment     what was reserved
 *     ServiceJourney  the operational visit
 *     JourneyStage    what was actually performed
 *     QueueTicket     the waiting, calling and routing around a stage
 *
 * ## The map
 *
 *     waiting ──call──▶ called ──(recall: stays called, appends history)
 *        │                 │
 *        │                 ├──▶ serving ──▶ completed
 *        │                 │
 *        ├──hold/skip──▶ held ──resume──▶ waiting
 *        │
 *        └──cancel──▶ cancelled
 *
 * ## `serving` and `completed` are JOURNEY's to give, not the queue's
 *
 * The map below permits them from any open state, because Journey can report a
 * stage starting or settling whatever the queue was doing — a stylist who
 * simply begins the next customer never pressed "call", and a stage skipped
 * before anybody was called still settles its ticket.
 *
 * But NO QUEUE ACTION PRODUCES THEM. There is no "complete ticket" endpoint and
 * no "start serving" write: the only writer is
 * `Queue\Application\SyncTicketsWithJourney`, reacting to a Journey fact. An
 * architecture test scans this module to keep it that way, because a queue
 * button that could mark a ticket completed while its stage was still waiting
 * would make the two domains disagree about a customer in a chair
 * (docs/17-QUEUE.md §12, correction 3).
 *
 * Equally, nothing operator-driven leaves `serving`. A host cannot hold, skip,
 * transfer or cancel a ticket whose service has begun; somebody who walks out
 * mid-service is an ABANDONED VISIT, which ends the visit and closes its
 * tickets together (§39).
 *
 * ## There is no `skipped` state
 *
 * A skip is an ACTION whose outcome is `held`: the customer did not answer, so
 * they come out of the call rotation and stay recoverable if they walk back in.
 * A permanent `skipped` state would strand them, and it would be confused with
 * `StageStatus::Skipped`, which means something completely different — the
 * customer declined the service (§15).
 */
enum TicketState: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Serving = 'serving';
    case Held = 'held';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            /*
             * `→ serving` and `→ completed` appear from every open state
             * because JOURNEY decides them, and Journey does not consult the
             * queue: a stylist may start the next customer without anybody
             * calling them, and a stage skipped before a call still settles its
             * ticket. No queue Action produces either — see the class note.
             */
            self::Waiting => [self::Called, self::Serving, self::Held, self::Completed, self::Cancelled],
            /*
             * `called → called` is a RECALL: same state, new history row, new
             * announcement. Listed here rather than special-cased somewhere
             * else, so the map is the whole truth (§13).
             *
             * `called → waiting` is a transfer: the destination changed, so the
             * customer has to be called again, to somewhere else.
             */
            self::Called => [
                self::Called, self::Waiting, self::Serving,
                self::Held, self::Completed, self::Cancelled,
            ],
            self::Serving => [self::Completed, self::Cancelled],
            self::Held => [self::Waiting, self::Serving, self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Still in the queue: not finished and not called off.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Eligible to be called next.
     *
     * `held` is deliberately absent — that is what holding MEANS — and so is
     * `called`, which is already somebody's responsibility (§20).
     *
     * @return list<string>
     */
    public static function callableValues(): array
    {
        return [self::Waiting->value];
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [
            self::Waiting->value,
            self::Called->value,
            self::Serving->value,
            self::Held->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => __('Waiting'),
            self::Called => __('Called'),
            self::Serving => __('Serving'),
            self::Held => __('On hold'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
