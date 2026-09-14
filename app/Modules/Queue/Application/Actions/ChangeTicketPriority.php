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
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\TicketMutation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Move somebody up the queue, explicitly.
 *
 * ## A number, set by a person
 *
 * 0 normal, 10 high, 20 urgent — and a center that wants something in between
 * sets 15 rather than waiting for a release. The value is only ever set by
 * somebody who decided to set it (docs/17-QUEUE.md §10).
 *
 * ## Never inferred
 *
 * Not from age, not from spend, not from a tag, not from a model. Priority in a
 * waiting room is a judgement a person makes and has to be able to defend, so
 * it is recorded with who made it — which is exactly what an inferred priority
 * could not give anybody.
 *
 * ## It does not jump a called ticket
 *
 * Ordering is `priority DESC, issued_at ASC, id ASC`, and only WAITING tickets
 * are eligible to be called. Raising somebody's priority changes who is next,
 * not who is currently standing at the counter.
 */
final class ChangeTicketPriority
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
        int $priority,
        User $actingUser,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): QueueTicket {
        $this->access->ensure(
            $actingUser,
            Permission::QueueManage,
            (int) $ticket->branch_id,
            'You may not change queue priority.',
        );

        if ($priority < 0 || $priority > 255) {
            throw QueueFailed::policy('A queue priority must be between 0 and 255.');
        }

        $before = $ticket->priority;
        $trimmed = $this->reason($reason);

        $changed = $this->mutation->apply(
            $ticket,
            $actingUser,
            fn (QueueTicket $locked): TicketChange => new TicketChange(
                // The ticket does not MOVE, so the state machine is not
                // consulted — but a finished ticket is still refused, which is
                // what `stateless` means here (§5).
                state: $locked->state,
                event: TicketEventType::PriorityChanged,
                attributes: ['priority' => $priority],
                reason: $trimmed,
                stateless: true,
            ),
            ($now ?? CarbonImmutable::now())->utc(),
        );

        $this->audit->record(new AuditEvent(
            action: 'queue.ticket.priority_changed',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $changed->uuid,
            targetLabel: $changed->display_number,
            before: ['priority' => $before],
            after: ['priority' => $changed->priority],
            meta: $trimmed === null ? [] : ['reason' => $trimmed],
        ));

        return $changed;
    }

    private function reason(?string $reason): ?string
    {
        $trimmed = trim((string) $reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }
}
