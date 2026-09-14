<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Queue\Domain\Enums\TicketEventType;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\Models\QueueTicketEvent;
use App\Modules\Queue\Domain\TicketNumbers;
use App\Modules\Queue\Domain\TicketPrefix;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Give this customer a number for this piece of work.
 *
 * ## Issued when the workflow needs it, never in advance
 *
 * A ticket exists to make somebody wait in the right place. Creating one when
 * an appointment is booked would put months of dead numbers in the table, and
 * would burn a day's sequence on customers who have not arrived. So a ticket is
 * issued against a STAGE that is waiting now (docs/17-QUEUE.md §22).
 *
 * ## One open ticket per stage, as a database invariant
 *
 * Three layers, the pattern check-in proved:
 *
 *   1. a read, which handles every ordinary double-click;
 *   2. `unique(active_journey_stage_id)`, which is the actual guarantee;
 *   3. a catch that re-reads and returns the winner, so the loser of a genuine
 *      race gets the canonical ticket rather than a 500 (§11, §59).
 *
 * ## The number
 *
 * `prefix + sequence`, where the prefix comes from the service point if one was
 * chosen, else the stage's department, else `A`. The sequence is a locked row
 * per branch per local day per prefix — see {@see TicketNumbers} for why
 * anything simpler hands two customers the same number.
 */
final class IssueTicket
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly TicketNumbers $numbers,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{service_point?: string|null, priority?: int|null, source?: string|null}  $options
     *
     * @throws QueueFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        JourneyStage $stage,
        User $actingUser,
        array $options = [],
        ?CarbonImmutable $now = null,
    ): QueueTicket {
        /*
         * The QUEUE entitlement, not booking. A center without it still takes
         * walk-ins and still runs the journey board; it simply hands nobody a
         * number (§19).
         */
        $this->entitlements->ensure('queue_management');

        $now = ($now ?? CarbonImmutable::now())->utc();

        $stage->loadMissing(['journey.appointment', 'department']);

        $branch = $this->branch($stage);

        $this->authorize($branch, $actingUser);

        $existing = $this->openTicketFor($stage);

        if ($existing instanceof QueueTicket) {
            return $existing;
        }

        if ($stage->isTerminal()) {
            throw QueueFailed::policy(
                'That service is already finished, so there is nobody to call.',
                ['status' => $stage->status->value],
            );
        }

        $point = $this->servicePoint($options['service_point'] ?? null, $branch);
        $prefix = $this->prefix($stage, $point);
        $priority = $this->priority($options['priority'] ?? null);

        // The BRANCH-LOCAL day. The sequence resets at the center's midnight,
        // not the server's (§6).
        $businessDate = BranchClock::localDate($now, $branch->timezone);

        try {
            /** @var QueueTicket $ticket */
            $ticket = DB::connection('tenant')->transaction(
                fn (): QueueTicket => $this->create(
                    $stage,
                    $branch,
                    $point,
                    $prefix,
                    $businessDate,
                    $priority,
                    is_string($options['source'] ?? null) ? (string) $options['source'] : 'staff',
                    $actingUser,
                    $now,
                )
            );
        } catch (UniqueConstraintViolationException) {
            // Another press won. Its ticket is the canonical one, and handing it
            // back is indistinguishable from having been the winner.
            $winner = $this->openTicketFor($stage);

            if ($winner instanceof QueueTicket) {
                return $winner;
            }

            throw QueueFailed::policy('That ticket could not be issued. Please try again.');
        }

        $this->audit->record(new AuditEvent(
            action: 'queue.ticket.issued',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: QueueTicket::class,
            targetId: $ticket->uuid,
            targetLabel: $ticket->display_number,
            after: [
                'number' => $ticket->display_number,
                'state' => $ticket->state->value,
                'priority' => $ticket->priority,
                'branch_id' => $ticket->branch_id,
                'department_id' => $ticket->department_id,
                'service_point_id' => $ticket->service_point_id,
            ],
        ));

        return $ticket;
    }

    private function create(
        JourneyStage $stage,
        Branch $branch,
        ?QueueServicePoint $point,
        string $prefix,
        string $businessDate,
        int $priority,
        string $source,
        User $actingUser,
        CarbonImmutable $now,
    ): QueueTicket {
        $number = $this->numbers->next((int) $branch->getKey(), $businessDate, $prefix);

        /** @var QueueTicket $ticket */
        $ticket = QueueTicket::query()->create([
            'branch_id' => $branch->getKey(),
            'service_journey_id' => $stage->service_journey_id,
            'journey_stage_id' => $stage->getKey(),
            // Mirrors the stage while open; nulled on close. THE invariant.
            'active_journey_stage_id' => $stage->getKey(),
            'department_id' => $stage->department_id,
            'service_point_id' => $point?->getKey(),
            'business_date' => $businessDate,
            'prefix' => $prefix,
            'number' => $number,
            // Snapshotted: it is printed on paper in somebody's hand, and a
            // later change to the padding must not renumber it (§6).
            'display_number' => TicketPrefix::format($prefix, $number),
            'priority' => $priority,
            'state' => TicketState::Waiting,
            'issued_at' => $now,
            'source' => $source,
            'issued_by_type' => 'staff',
            'issued_by_id' => $actingUser->uuid,
            'issued_by_label' => $actingUser->name,
        ]);

        // The first history row. Written here rather than through
        // `TicketMutation`, which locks a ticket that by definition does not
        // exist yet — but the shape is identical (§13).
        QueueTicketEvent::query()->create([
            'queue_ticket_id' => $ticket->getKey(),
            'sequence' => 1,
            'type' => TicketEventType::Issued,
            'from_state' => null,
            'to_state' => TicketState::Waiting->value,
            'service_point_id' => $point?->getKey(),
            'department_id' => $stage->department_id,
            'actor_type' => 'staff',
            'actor_id' => $actingUser->uuid,
            'actor_label' => $actingUser->name,
            'occurred_at' => $now,
        ]);

        return $ticket;
    }

    private function openTicketFor(JourneyStage $stage): ?QueueTicket
    {
        return QueueTicket::query()
            ->where('active_journey_stage_id', $stage->getKey())
            ->first();
    }

    /**
     * @throws QueueFailed
     */
    private function branch(JourneyStage $stage): Branch
    {
        $branchId = $stage->journey?->branchId() ?? 0;

        $branch = $branchId > 0 ? Branch::query()->find($branchId) : null;

        if (! $branch instanceof Branch) {
            throw QueueFailed::policy('That visit is not attached to a branch.');
        }

        return $branch;
    }

    /**
     * @throws QueueFailed
     */
    private function servicePoint(?string $uuid, Branch $branch): ?QueueServicePoint
    {
        if ($uuid === null) {
            // The normal case: a number first, a destination when somebody
            // calls it.
            return null;
        }

        $point = QueueServicePoint::query()
            ->usable()
            ->where('uuid', $uuid)
            // BRANCH-SCOPED, always. A ticket sent to another branch's counter
            // is the one thing §20 forbids outright.
            ->where('branch_id', $branch->getKey())
            ->first();

        if (! $point instanceof QueueServicePoint) {
            throw QueueFailed::policy('That service point is not available at this branch.');
        }

        return $point;
    }

    /**
     * Service point, then department, then `A`.
     */
    private function prefix(JourneyStage $stage, ?QueueServicePoint $point): string
    {
        $candidate = $point->ticket_prefix ?? $stage->department?->queue_prefix;

        // Sanitised rather than validated: a prefix already in the database
        // must not make issuing a ticket impossible. Bad input is refused where
        // it is ENTERED, which is the service point and department Actions.
        return TicketPrefix::sanitise($candidate);
    }

    /**
     * @throws QueueFailed
     */
    private function priority(?int $priority): int
    {
        if ($priority === null) {
            return 0;
        }

        if ($priority < 0 || $priority > 255) {
            throw QueueFailed::policy('A queue priority must be between 0 and 255.');
        }

        return $priority;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Branch $branch, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::QueueManage)) {
            throw new AuthorizationException('You may not issue queue tickets.');
        }

        if (! $actingUser->canAccessBranch((int) $branch->getKey())) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
