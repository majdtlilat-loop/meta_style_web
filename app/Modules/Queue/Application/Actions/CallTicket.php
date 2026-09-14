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
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\TicketMutation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Ticket A012, please go to Room 3."
 *
 * ## Calling is not starting
 *
 * It is an instruction to a customer to walk somewhere. The service begins when
 * a member of staff actually starts the JourneyStage, which is a different
 * Action in a different module and stamps a different timestamp. Conflating
 * them would make every service look as though it began the moment the number
 * appeared on a screen (docs/17-QUEUE.md §12).
 *
 * Calling a ticket to a service point that happens to be a room does NOT
 * reserve that room either. Display destination and actual resource capacity
 * are different concerns, and the second one belongs to the Phase 7 Journey
 * Actions (§41, ADR-050).
 *
 * ## Recall appends, it does not overwrite
 *
 * A second call writes a second history row with its own identifier. That is
 * what lets a television announce the number again — a poll that saw only an
 * unchanged `called_at` would stay silent, which is the opposite of what
 * pressing recall means — and it is what lets somebody reviewing a busy
 * Saturday see "called three times" (§13, correction 2).
 */
final class CallTicket
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
        ?string $servicePointUuid = null,
        ?CarbonImmutable $now = null,
    ): QueueTicket {
        $this->access->ensure(
            $actingUser,
            Permission::QueueCall,
            (int) $ticket->branch_id,
            'You may not call queue tickets.',
        );

        $point = $this->servicePoint($servicePointUuid, $ticket);

        $at = ($now ?? CarbonImmutable::now())->utc();

        $called = $this->mutation->apply(
            $ticket,
            $actingUser,
            function (QueueTicket $locked) use ($point, $at): TicketChange {
                /*
                 * Decided against the LOCKED row. A host whose screen was
                 * rendered thirty seconds ago may be looking at a ticket
                 * somebody else has already served.
                 */
                if ($locked->state === TicketState::Serving) {
                    throw QueueFailed::invalidTransition(
                        'That customer is already being served.',
                        ['state' => $locked->state->value],
                    );
                }

                $recall = $locked->state === TicketState::Called;

                return new TicketChange(
                    state: TicketState::Called,
                    event: $recall ? TicketEventType::Recalled : TicketEventType::Called,
                    attributes: [
                        // The FIRST call is kept for good: it is what every
                        // waiting-time figure is measured to (§38).
                        'first_called_at' => $locked->first_called_at ?? $at,
                        'last_called_at' => $at,
                        'call_count' => $locked->call_count + 1,
                        'service_point_id' => $point?->getKey() ?? $locked->service_point_id,
                        // A held ticket that is called comes off hold.
                        'held_at' => null,
                        'hold_reason' => null,
                    ],
                    servicePointId: $point?->getKey() ?? $locked->service_point_id,
                );
            },
            $at,
        );

        $this->audit->record(new AuditEvent(
            action: $called->call_count > 1 ? 'queue.ticket.recalled' : 'queue.ticket.called',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $called->uuid,
            targetLabel: $called->display_number,
            after: [
                'state' => $called->state->value,
                'call_count' => $called->call_count,
                'service_point_id' => $called->service_point_id,
            ],
        ));

        return $called;
    }

    /**
     * @throws QueueFailed
     */
    private function servicePoint(?string $uuid, QueueTicket $ticket): ?QueueServicePoint
    {
        if ($uuid === null) {
            // Calling without naming a destination is legitimate: a one-room
            // barbershop has nowhere else to send anybody.
            return null;
        }

        $point = QueueServicePoint::query()
            ->usable()
            ->where('uuid', $uuid)
            ->where('branch_id', $ticket->branch_id)
            ->first();

        if (! $point instanceof QueueServicePoint) {
            throw QueueFailed::policy('That service point is not available at this branch.');
        }

        return $point;
    }
}
