<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Queue\Application\QueueAccess;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\AbortJourney;
use App\Modules\ServiceJourney\Application\Actions\CancelVisit;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The customer left the center.
 *
 * ## Three domains, one decision
 *
 * Walking out is not three separate facts that a host has to remember to record
 * in three places. It is one event with consequences in each
 * (docs/17-QUEUE.md §39):
 *
 *     the VISIT     is abandoned                  — Journey
 *     the TICKETS   stop being callable           — Queue, via the synchronizer
 *     the BOOKING   may need cancelling           — Booking, through its own Action
 *
 * ## Queue does not mutate all three itself
 *
 * It calls ONE Journey Action and lets the consequences follow. Aborting the
 * journey dispatches `JourneyAborted`, which the synchronizer turns into
 * cancelled tickets inside the same transaction — so a visit cannot end while
 * its number is still on a television.
 *
 * For a BOOKED visit the supported flow is Journey's `CancelVisit`, which
 * aborts the journey and then calls the BOOKING lifecycle Action. Journey never
 * writes `appointments.status` and neither does this; a second implementation
 * of cancellation would have its own idea of which transitions are legal and
 * its own missing audit entry (Phase 7 §28).
 *
 * A WALK-IN has no appointment, so there is nothing to cancel and
 * `AbortJourney` is the whole story.
 */
final class AbandonQueuedVisit
{
    public function __construct(
        private readonly QueueAccess $access,
        private readonly CancelVisit $cancelVisit,
        private readonly AbortJourney $abort,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(
        ServiceJourney $journey,
        User $actingUser,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): ServiceJourney {
        $this->access->ensure(
            $actingUser,
            Permission::QueueManage,
            $journey->branchId(),
            'You may not end visits from the queue.',
        );

        $journey->loadMissing('appointment');

        $appointment = $journey->appointment;

        if ($appointment instanceof Appointment) {
            // Ends the visit AND the reservation, through the Actions that own
            // each of them.
            ($this->cancelVisit)($appointment, $actingUser, $reason, $now);
        } else {
            ($this->abort)($journey, $actingUser, $reason, $now);
        }

        return $journey->refresh();
    }

    /**
     * The tickets this visit still holds, for a caller that wants to show what
     * will be closed before closing it.
     *
     * @return list<QueueTicket>
     */
    public function openTickets(ServiceJourney $journey): array
    {
        /** @var list<QueueTicket> $tickets */
        $tickets = QueueTicket::query()
            ->where('service_journey_id', $journey->getKey())
            ->open()
            ->get()
            ->all();

        return $tickets;
    }
}
