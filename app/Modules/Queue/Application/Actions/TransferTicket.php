<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Departments\Domain\Models\Department;
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
 * Send this customer somewhere else.
 *
 * "A012 was waiting for the laser desk; the room assignment desk will see them
 * first."
 *
 * ## Back to `waiting`, on purpose
 *
 * A transferred ticket that stayed `called` would be a customer standing at a
 * counter nobody is expecting them at. The destination changed, so they have to
 * be called again — to the new place (docs/17-QUEUE.md §16).
 *
 * ## The history survives
 *
 * Earlier calls are not touched, and the transfer itself is appended with both
 * the old and the new destination. "Called twice to Reception, transferred to
 * Laser, called once more" is the readable record; a `service_point_id` column
 * on its own would have said only where they ended up.
 *
 * ## Journey is not rewritten
 *
 * A transfer moves a WAITING position, not a service. The stage keeps its
 * department, because the department is what the service belongs to and moving
 * a customer to another desk does not change which service they booked. Moving
 * the WORK between employees or departments is `HandoffStage`, in Journey (§16).
 *
 * ## Same branch, always
 *
 * A cross-branch transfer is out of scope and refused, not silently permitted:
 * the number belongs to another branch's daily sequence and would appear on the
 * wrong television (§20).
 */
final class TransferTicket
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
        ?string $departmentUuid = null,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): QueueTicket {
        $this->access->ensure(
            $actingUser,
            Permission::QueueManage,
            (int) $ticket->branch_id,
            'You may not transfer queue tickets.',
        );

        if ($servicePointUuid === null && $departmentUuid === null) {
            throw QueueFailed::policy('A transfer needs a destination.');
        }

        $point = $this->servicePoint($servicePointUuid, $ticket);
        $department = $this->department($departmentUuid);

        $at = ($now ?? CarbonImmutable::now())->utc();
        $trimmed = $this->reason($reason);

        $transferred = $this->mutation->apply(
            $ticket,
            $actingUser,
            function (QueueTicket $locked) use ($point, $department, $trimmed): TicketChange {
                if ($locked->state === TicketState::Serving) {
                    throw QueueFailed::invalidTransition(
                        'That customer is already being served. Hand the work on instead.',
                        ['state' => $locked->state->value],
                    );
                }

                if ($locked->state->isTerminal()) {
                    throw QueueFailed::invalidTransition(
                        "A ticket that is {$locked->state->value} cannot be transferred.",
                        ['state' => $locked->state->value],
                    );
                }

                return new TicketChange(
                    state: TicketState::Waiting,
                    event: TicketEventType::Transferred,
                    attributes: [
                        'service_point_id' => $point?->getKey(),
                        'department_id' => $department?->getKey() ?? $locked->department_id,
                        // They must be called again, to the new place.
                        'held_at' => null,
                        'hold_reason' => null,
                    ],
                    reason: $trimmed,
                    servicePointId: $point?->getKey(),
                    departmentId: $department?->getKey() ?? $locked->department_id,
                    /*
                     * Re-routing a ticket that is ALREADY waiting does not move
                     * it — a host can change the destination before anybody has
                     * been called. Declaring that explicitly keeps the state map
                     * free of a `waiting → waiting` edge, which would otherwise
                     * have to exist and would quietly permit other self-moves
                     * nobody intended (§5, §16).
                     */
                    stateless: $locked->state === TicketState::Waiting,
                );
            },
            $at,
        );

        $this->audit->record(new AuditEvent(
            action: 'queue.ticket.transferred',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $transferred->uuid,
            targetLabel: $transferred->display_number,
            before: [
                'service_point_id' => $ticket->getOriginal('service_point_id'),
                'department_id' => $ticket->getOriginal('department_id'),
            ],
            after: [
                'service_point_id' => $transferred->service_point_id,
                'department_id' => $transferred->department_id,
                'state' => $transferred->state->value,
            ],
            meta: $trimmed === null ? [] : ['reason' => $trimmed],
        ));

        return $transferred;
    }

    /**
     * @throws QueueFailed
     */
    private function servicePoint(?string $uuid, QueueTicket $ticket): ?QueueServicePoint
    {
        if ($uuid === null) {
            return null;
        }

        $point = QueueServicePoint::query()
            ->usable()
            ->where('uuid', $uuid)
            // The branch check that makes cross-branch transfer impossible
            // rather than merely discouraged.
            ->where('branch_id', $ticket->branch_id)
            ->first();

        if (! $point instanceof QueueServicePoint) {
            throw QueueFailed::policy('That service point is not available at this branch.');
        }

        return $point;
    }

    /**
     * @throws QueueFailed
     */
    private function department(?string $uuid): ?Department
    {
        if ($uuid === null) {
            return null;
        }

        $department = Department::query()->where('uuid', $uuid)->first();

        if (! $department instanceof Department) {
            throw QueueFailed::policy('That department was not found.');
        }

        return $department;
    }

    private function reason(?string $reason): ?string
    {
        $trimmed = trim((string) $reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }
}
