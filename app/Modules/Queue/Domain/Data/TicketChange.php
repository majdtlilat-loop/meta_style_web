<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Data;

use App\Modules\Queue\Domain\Enums\TicketEventType;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\TicketMutation;

/**
 * What one queue Action wants to do to a ticket, decided under the lock.
 *
 * Every mutating Action produces exactly one of these and hands it to
 * {@see TicketMutation}, which is the only thing that
 * writes a ticket. That is what makes "lock, validate, mutate, append one
 * history row" a single implementation rather than a rule nine Actions each
 * have to remember (docs/17-QUEUE.md §5, correction 5).
 *
 * `stateless` is for a change that does not move the ticket — a priority
 * adjustment. Without it the state machine would have to allow every state to
 * transition to itself, which would quietly permit a recall from `waiting`.
 */
final readonly class TicketChange
{
    /**
     * @param  array<string, mixed>  $attributes  columns to write on the ticket
     */
    public function __construct(
        public TicketState $state,
        public TicketEventType $event,
        public array $attributes = [],
        public ?string $reason = null,
        public ?int $servicePointId = null,
        public ?int $departmentId = null,
        public bool $stateless = false,
    ) {}
}
