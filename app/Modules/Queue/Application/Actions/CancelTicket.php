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
 * Take a number out of the queue for good.
 *
 * ## Only the number, and only before service
 *
 * Cancelling a ticket cancels a WAITING POSITION. The visit is untouched: the
 * stage stays `waiting`, and a host who wants the customer back can issue a new
 * ticket — the unique index allows it, because the cancelled one released
 * `active_journey_stage_id` (docs/17-QUEUE.md §11).
 *
 * ## A ticket being served cannot be cancelled from here
 *
 * That would leave `JourneyStage.status = in_service` beside
 * `QueueTicket.state = cancelled`: two domains disagreeing about a customer who
 * is physically in a chair. Somebody who walks out mid-service is an ABANDONED
 * VISIT, which is one orchestration that ends the visit and closes its tickets
 * together (correction 3, §39).
 *
 * The refusal names that flow rather than simply saying no.
 */
final class CancelTicket
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
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): QueueTicket {
        $this->access->ensure(
            $actingUser,
            Permission::QueueManage,
            (int) $ticket->branch_id,
            'You may not cancel queue tickets.',
        );

        $at = ($now ?? CarbonImmutable::now())->utc();
        $trimmed = $this->reason($reason);

        $cancelled = $this->mutation->apply(
            $ticket,
            $actingUser,
            function (QueueTicket $locked) use ($trimmed, $at): TicketChange {
                if ($locked->state === TicketState::Serving) {
                    throw QueueFailed::invalidTransition(
                        'That customer is already being served. End the visit instead.',
                        ['state' => $locked->state->value],
                    );
                }

                return new TicketChange(
                    state: TicketState::Cancelled,
                    event: TicketEventType::Cancelled,
                    attributes: [
                        'closed_at' => $at,
                        'close_reason' => $trimmed,
                        // RELEASED. The stage may be ticketed again, and the
                        // unique index is what allows exactly one open ticket
                        // at a time rather than one ever.
                        'active_journey_stage_id' => null,
                    ],
                    reason: $trimmed,
                );
            },
            $at,
        );

        $this->audit->record(new AuditEvent(
            action: 'queue.ticket.cancelled',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $cancelled->uuid,
            targetLabel: $cancelled->display_number,
            after: ['state' => $cancelled->state->value],
            meta: $trimmed === null ? [] : ['reason' => $trimmed],
        ));

        return $cancelled;
    }

    private function reason(?string $reason): ?string
    {
        $trimmed = trim((string) $reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }
}
