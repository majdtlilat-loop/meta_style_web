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
 * The customer came back. Put them in the queue again.
 *
 * ## They keep their place
 *
 * `issued_at` is not touched, and the call order is `priority DESC, issued_at
 * ASC, id ASC` — so somebody who stepped out for five minutes returns to where
 * they were rather than to the back of a Saturday queue. That is the behaviour
 * a host expects when they press resume, and it is why holding is a state
 * rather than a cancellation and a reissue (docs/17-QUEUE.md §14, §20).
 *
 * ## Nothing is undone
 *
 * The hold stays in the history. "Held at 10:40, resumed at 10:55" is the
 * record, and a resumed ticket that had been skipped keeps its `skip_count` —
 * because it was skipped, and pretending otherwise makes the number useless.
 */
final class ResumeTicket
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
    public function __invoke(QueueTicket $ticket, User $actingUser, ?CarbonImmutable $now = null): QueueTicket
    {
        $this->access->ensure(
            $actingUser,
            Permission::QueueManage,
            (int) $ticket->branch_id,
            'You may not change queue tickets.',
        );

        $at = ($now ?? CarbonImmutable::now())->utc();

        $resumed = $this->mutation->apply(
            $ticket,
            $actingUser,
            function (QueueTicket $locked): TicketChange {
                if ($locked->state !== TicketState::Held) {
                    throw QueueFailed::invalidTransition(
                        'That ticket is not on hold.',
                        ['state' => $locked->state->value],
                    );
                }

                return new TicketChange(
                    state: TicketState::Waiting,
                    event: TicketEventType::Resumed,
                    attributes: [
                        'held_at' => null,
                        'hold_reason' => null,
                    ],
                );
            },
            $at,
        );

        $this->audit->record(new AuditEvent(
            action: 'queue.ticket.resumed',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $resumed->uuid,
            targetLabel: $resumed->display_number,
            after: ['state' => $resumed->state->value],
        ));

        return $resumed;
    }
}
