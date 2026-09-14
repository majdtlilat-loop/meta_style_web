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
 * The service is finished. Close the ticket by finishing the WORK.
 *
 * ## There is deliberately no "complete ticket" endpoint
 *
 * A queue button that marked a ticket completed while its stage was still
 * `waiting` or `in_service` would make the two domains disagree about a
 * customer sitting in a chair — and the ticket, being the thing on the
 * television, would be the one people believed (docs/17-QUEUE.md §12,
 * correction 3).
 *
 * So completion follows the JOURNEY FACT. This Action finishes the stage
 * through Journey's `TransitionStage`, which releases the actual resource
 * holds and stamps `service_completed_at`; the synchronizer then closes the
 * ticket because the stage settled.
 *
 * Finishing the same stage from the Journey board produces exactly the same
 * result, through exactly the same event. That is the property correction 4
 * asked to be provable, and it is provable because there is only one path.
 */
final class CompleteServingTicket
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

        if ($stage->status !== StageStatus::InService) {
            // Named plainly, because "finish a service that has not started" is
            // a real thing somebody will try from a busy board.
            throw QueueFailed::invalidTransition(
                "That service is {$stage->status->value}, so it cannot be finished.",
                ['status' => $stage->status->value],
            );
        }

        ($this->transition)($stage, StageStatus::Completed, $actingUser, [], $now);

        return $ticket->refresh();
    }
}
