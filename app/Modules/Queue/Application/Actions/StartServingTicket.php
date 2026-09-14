<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\QueueAccess;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The customer arrived at the counter. Begin the service.
 *
 * ## The queue does not start anything
 *
 * It ORCHESTRATES. This Action calls Journey's `TransitionStage`, which is the
 * single implementation of "a service began" — it takes the branch lock, runs
 * the combined resource-capacity check, opens the actual resource holds, stamps
 * `service_started_at` and writes its own audit entry (ADR-047, ADR-050).
 *
 * The ticket then moves to `serving` because Journey said the stage started,
 * through the synchronizer — not because this Action wrote it. That is what
 * makes the Journey board and the Queue board produce identical results from
 * their "start" buttons, and it is why there is no queue-side write of
 * `TicketState::Serving` anywhere (docs/17-QUEUE.md §12, correction 3).
 *
 * ## Authorization is not duplicated
 *
 * The queue entitlement and branch scope are checked here; the permission to
 * start a service belongs to Journey and is checked there. Restating
 * `journey.stage.start` in this module would be a second copy of a rule that
 * would eventually disagree with the first.
 */
final class StartServingTicket
{
    public function __construct(
        private readonly QueueAccess $access,
        private readonly TransitionStage $transition,
    ) {}

    /**
     * @throws QueueFailed
     * @throws AuthorizationException
     */
    public function __invoke(QueueTicket $ticket, User $actingUser, ?CarbonImmutable $now = null): QueueTicket
    {
        $this->access->ensure(
            $actingUser,
            Permission::QueueView,
            (int) $ticket->branch_id,
            'You may not work the queue.',
        );

        $stage = $ticket->stage;

        if (! $stage instanceof JourneyStage) {
            throw QueueFailed::policy('That ticket is not attached to a service.');
        }

        if ($stage->status !== StageStatus::Waiting) {
            throw QueueFailed::invalidTransition(
                "That service is already {$stage->status->value}.",
                ['status' => $stage->status->value],
            );
        }

        // Journey's Action. Its transaction, its rules, its audit entry — and
        // the event it dispatches is what moves the ticket.
        ($this->transition)($stage, StageStatus::InService, $actingUser, [], $now);

        return $ticket->refresh();
    }
}
