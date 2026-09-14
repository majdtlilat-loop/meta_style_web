<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\QueueAccess;
use App\Modules\Queue\Domain\Data\TicketChange;
use App\Modules\Queue\Domain\Enums\TicketEventType;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\TicketMutation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Take a ticket out of the call rotation without ending it.
 *
 * ## Two doors into one state
 *
 *   HOLD  — the host knows why. "Gone to the pharmacy, back in ten minutes."
 *   SKIP  — called, no answer. The host moves on and this one stops blocking.
 *
 * Both produce `held`, and they are recorded as different history rows because
 * they mean different things when somebody reads the day back. A skip also
 * increments `skip_count`, which is the number that says "we called and nobody
 * came" as distinct from "they asked us to wait" (docs/17-QUEUE.md §14, §15).
 *
 * ## A queue skip is NOT a stage skip
 *
 * `StageStatus::Skipped` means the customer DECLINED the service — the beard
 * trim they changed their mind about. Missing a queue call means nothing of the
 * kind, and turning one into the other would put "customer declined" in the
 * record of somebody who was in the toilet. They are different concepts in
 * different modules and this Action never touches the stage (§15).
 *
 * ## Nothing holds a ticket that is being served
 *
 * Once the stage is genuinely `in_service` the queue has done its job. Holding
 * from there would make the ticket and the stage disagree about the customer in
 * the chair. Somebody who leaves mid-service is an ABANDONED VISIT, which is a
 * higher-level orchestration that ends both (correction 3, §39).
 */
final class HoldTicket
{
    public function __construct(
        private readonly QueueAccess $access,
        private readonly TicketMutation $mutation,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws QueueFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        QueueTicket $ticket,
        User $actingUser,
        bool $skipped = false,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): QueueTicket {
        /*
         * A skip is part of calling — the same host, at the same desk, pressing
         * the button next to it. A deliberate hold is a management decision
         * about somebody's place in the queue.
         */
        $this->access->ensure(
            $actingUser,
            $skipped ? Permission::QueueCall : Permission::QueueManage,
            (int) $ticket->branch_id,
            $skipped ? 'You may not skip queue tickets.' : 'You may not hold queue tickets.',
        );

        $at = ($now ?? CarbonImmutable::now())->utc();
        $trimmed = $this->reason($reason, $skipped);

        $held = $this->mutation->apply(
            $ticket,
            $actingUser,
            function (QueueTicket $locked) use ($skipped, $trimmed, $at): TicketChange {
                if ($locked->state === TicketState::Serving) {
                    throw QueueFailed::invalidTransition(
                        'That customer is already being served. End the visit instead.',
                        ['state' => $locked->state->value],
                    );
                }

                if ($skipped && $locked->state !== TicketState::Called) {
                    // Skipping means "we called and nobody came". A ticket
                    // nobody has called yet has not been skipped; it is still
                    // waiting.
                    throw QueueFailed::invalidTransition(
                        'Only a ticket that has been called can be skipped.',
                        ['state' => $locked->state->value],
                    );
                }

                return new TicketChange(
                    state: TicketState::Held,
                    event: $skipped ? TicketEventType::Skipped : TicketEventType::Held,
                    attributes: [
                        'held_at' => $at,
                        'hold_reason' => $trimmed,
                        'skip_count' => $locked->skip_count + ($skipped ? 1 : 0),
                    ],
                    reason: $trimmed,
                );
            },
            $at,
        );

        $this->audit->record(new AuditEvent(
            action: $skipped ? 'queue.ticket.skipped' : 'queue.ticket.held',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $held->uuid,
            targetLabel: $held->display_number,
            after: ['state' => $held->state->value, 'skip_count' => $held->skip_count],
            meta: $trimmed === null ? [] : ['reason' => $trimmed],
        ));

        return $held;
    }

    private function reason(?string $reason, bool $skipped): ?string
    {
        $trimmed = trim((string) $reason);

        if ($trimmed !== '') {
            return mb_substr($trimmed, 0, 190);
        }

        // A skip always has a reason even when nobody typed one, because the
        // reason is the whole event: nobody answered.
        return $skipped ? 'No response' : null;
    }
}
