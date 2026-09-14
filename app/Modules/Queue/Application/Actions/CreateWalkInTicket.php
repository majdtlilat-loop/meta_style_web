<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The reception flow, in one Action: somebody walks in and gets a number.
 *
 * ## One orchestration, one transaction
 *
 * Resolve the customer, create the visit, derive its stages, issue a ticket for
 * the first one. A failure part-way through leaves nothing behind — no customer
 * with no visit, no visit with no ticket — which is what makes the reception
 * screen safe to press twice (docs/17-QUEUE.md §23, §59).
 *
 * Both halves are independently idempotent: the walk-in on its token, the
 * ticket on `unique(active_journey_stage_id)`. Pressing the button twice with
 * the same token therefore returns the same visit AND the same number, rather
 * than the same visit and a second ticket.
 *
 * ## Deliberately a QUEUE Action
 *
 * Walk-ins themselves belong to Journey and are gated on `booking` — a center
 * without the queue still takes people who walk in, using
 * {@see CreateWalkInVisit} from the visit board. This Action is the version
 * that also hands them a number, so it is gated on `queue_management`, and
 * refusing it early is what keeps the entitlement from ever producing a visit
 * with half a queue (§19).
 *
 * ## A ticket for the FIRST stage only
 *
 * A visit with three services does not get three numbers at the door. Each
 * later stage is ticketed when the workflow reaches it, because a queue is
 * about the wait that is happening now (§24).
 */
final class CreateWalkInTicket
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly CreateWalkInVisit $visits,
        private readonly IssueTicket $tickets,
    ) {}

    /**
     * @param  array{service_point?: string|null, priority?: int|null}  $options
     * @return array{journey: ServiceJourney, ticket: QueueTicket}
     *
     * @throws QueueFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        WalkInRequest $request,
        User $actingUser,
        array $options = [],
        ?CarbonImmutable $now = null,
    ): array {
        // Refused BEFORE anything is written, so an unentitled center never
        // ends up with a visit it cannot queue.
        $this->entitlements->ensure('queue_management');

        /** @var array{journey: ServiceJourney, ticket: QueueTicket} $result */
        $result = DB::connection('tenant')->transaction(
            function () use ($request, $actingUser, $options, $now): array {
                $journey = ($this->visits)($request, $actingUser, $now);

                $stage = $this->firstWaitingStage($journey);

                $ticket = ($this->tickets)($stage, $actingUser, [
                    'service_point' => $options['service_point'] ?? null,
                    'priority' => $options['priority'] ?? null,
                    'source' => 'walk_in',
                ], $now);

                return ['journey' => $journey, 'ticket' => $ticket];
            }
        );

        return $result;
    }

    /**
     * @throws QueueFailed
     */
    private function firstWaitingStage(ServiceJourney $journey): JourneyStage
    {
        $stage = $journey->stages()
            ->where('status', StageStatus::Waiting->value)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if (! $stage instanceof JourneyStage) {
            // Only reachable if the visit was created with no services, which
            // the walk-in Action refuses — so this is a guard, not a path.
            throw QueueFailed::policy('That visit has nothing waiting to be called.');
        }

        return $stage;
    }
}
