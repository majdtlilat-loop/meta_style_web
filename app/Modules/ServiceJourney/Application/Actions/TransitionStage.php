<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\TimeWindow;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\ServiceJourney\Application\JourneySnapshot;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageSettled;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageStarted;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\StageResources;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Every status change one stage of a visit can undergo: start, complete, skip.
 *
 * ONE ACTION, NOT THREE, for the same reason `TransitionAppointment` is one:
 * they differ in the permission required, the timestamp stamped, and one extra
 * rule apiece. Three near-identical classes would be three places to forget the
 * transition check. The state machine lives in {@see StageStatus}, so an
 * invalid move is refused by the enum rather than by whoever remembered to
 * write the `if` (docs/13-ROADMAP.md Phase 7 §19).
 *
 * ## Starting a stage takes the rooms
 *
 * The booking reserved them; starting the service is when they are actually
 * occupied. Usage rows open here with `assigned_at = now`, checked against a
 * COMBINED picture of the resource: other stages' open holds, so a room whose
 * previous customer has overrun refuses the next stage even though the plan
 * said it was free — and other bookings' committed reservations, so an early
 * start whose expected run would eat into a 10:30 booking is refused too
 * (§25, corrections §5, and {@see StageResources} for the full rule).
 *
 * Both the capacity read and the write happen inside one transaction with the
 * branch lock held, for the same reason booking does it (ADR-047): a check that
 * raced a booking is a check that passed for no reason.
 *
 * ## Finishing a stage releases them
 *
 * `released_at = now` on every open row. Nothing is deleted, so the intervals
 * survive: "Laser 1 from 10:00 to 10:15, Laser 2 from 10:15 to 10:40" is
 * readable afterwards.
 *
 * ## Actual times, never planned ones
 *
 * `service_started_at` is when the service actually began. The item's
 * `starts_at` — when it was meant to — is not touched. A customer who arrives
 * twenty minutes late has both facts recorded, which is what a later delay
 * report needs and what a single set of columns could not express (§27).
 */
final class TransitionStage
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly StageResources $resources,
        private readonly BranchLock $branches,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{reason?: string|null}  $options
     *
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        JourneyStage $stage,
        StageStatus $target,
        User $actingUser,
        array $options = [],
        ?CarbonImmutable $now = null,
    ): JourneyStage {
        $this->entitlements->ensure('booking');

        // UTC, explicitly. "Store UTC, compute in the branch timezone" is the
        // rule (CLAUDE.md), and an Action that stores whichever zone its caller
        // happened to be holding writes a wall clock three hours out without
        // anything failing — the values look plausible and sort wrongly.
        $now = ($now ?? CarbonImmutable::now())->utc();

        /*
         * Loaded here rather than assumed. An Action that relies on its caller
         * having eager-loaded the right relations is an Action that issues a
         * query per row the first time somebody calls it from a loop — and
         * `loadMissing` costs nothing when the caller already did the work.
         */
        $stage->loadMissing(['journey.appointment', 'item']);

        $this->authorize($stage, $target, $actingUser);

        $from = $stage->status;

        if (! $from->canTransitionTo($target)) {
            // Names both ends, because "cannot complete a stage that has not
            // started" is actionable at a desk and "invalid transition" is not.
            throw JourneyFailed::invalidTransition(
                "A stage that is {$from->value} cannot be marked {$target->value}.",
                ['from' => $from->value, 'to' => $target->value],
            );
        }

        $reason = $this->reason($options);

        if ($target === StageStatus::Skipped && $reason === null) {
            throw JourneyFailed::policy('Say why this service was skipped.');
        }

        $before = JourneySnapshot::stage($stage);

        DB::connection('tenant')->transaction(function () use ($stage, $target, $reason, $actingUser, $now): void {
            match ($target) {
                StageStatus::InService => $this->start($stage, $actingUser, $now),
                StageStatus::Completed => $this->complete($stage, $now),
                StageStatus::Skipped => $this->skip($stage, $reason, $now),
                StageStatus::Waiting => null,
            };
        });

        $stage->refresh();

        $this->audit->record(new AuditEvent(
            action: 'journey.stage.'.$this->verb($target),
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: JourneyStage::class,
            targetId: $stage->uuid,
            before: $before,
            after: JourneySnapshot::stage($stage),
            meta: $reason === null ? [] : ['reason' => $reason],
        ));

        return $stage;
    }

    /**
     * Begins the service and opens the actual resource holds.
     *
     * @throws JourneyFailed
     */
    private function start(JourneyStage $stage, User $actingUser, CarbonImmutable $now): void
    {
        $window = $this->expectedWindow($stage, $now);

        $branchId = (int) ($stage->journey?->branchId() ?? 0);

        /*
         * The SAME lock the Booking Engine takes, inside the same transaction
         * the usage rows are written in. Without it, a booking committed
         * between the capacity read and the insert would leave two holds on a
         * room that has one place (ADR-047).
         */
        if ($branchId > 0) {
            $this->branches->acquireOne($branchId);
        }

        /*
         * A booked stage re-checks the rooms the booking already chose; a
         * walk-in chooses them now, through the same allocator, against the
         * same combined occupancy. Both answers come back in one shape, so this
         * loop does not need to know which kind of visit it is serving
         * ({@see StageResources::planFor()}).
         */
        $plan = $this->resources->planFor($stage, $window, $this->branch($branchId));

        foreach ($plan as $hold) {
            $this->resources->open(
                $stage,
                $hold['resource'],
                $hold['quantity'],
                $now,
                (int) $actingUser->getKey(),
            );
        }

        $stage->forceFill([
            'status' => StageStatus::InService,
            'service_started_at' => $now,
        ])->save();

        // The visit itself starts when its first service does. `arrived_at`
        // already recorded when the customer walked in; these are different
        // moments and a waiting-time report needs both.
        $journey = $stage->journey;

        if ($journey !== null && $journey->started_at === null) {
            $journey->forceFill(['started_at' => $now])->save();
        }

        /*
         * STATED AS A FACT, inside this transaction.
         *
         * Anything that keeps a queue ticket in step with the floor listens
         * here; Journey does not know whether anybody does. Dispatching inside
         * the transaction is what makes the two consistent or neither of them
         * true — a listener that fails takes this stage's start down with it,
         * rather than leaving a stage `in_service` beside a ticket still saying
         * `called` (docs/17-QUEUE.md §12, correction 4).
         */
        Event::dispatch(new JourneyStageStarted(
            (int) $stage->getKey(),
            (int) $stage->service_journey_id,
            $stage->journey?->branchId() ?? 0,
        ));
    }

    private function complete(JourneyStage $stage, CarbonImmutable $now): void
    {
        $this->resources->releaseAll($stage, $now);

        $stage->forceFill([
            'status' => StageStatus::Completed,
            'service_completed_at' => $now,
        ])->save();

        $this->announceSettled($stage, StageStatus::Completed);
    }

    private function skip(JourneyStage $stage, ?string $reason, CarbonImmutable $now): void
    {
        // A skipped stage never started, so it holds nothing — but releasing is
        // harmless and keeps the invariant "a terminal stage holds no resource"
        // true without a special case somewhere else.
        $this->resources->releaseAll($stage, $now, $reason);

        $stage->forceFill([
            'status' => StageStatus::Skipped,
            'skip_reason' => $reason,
        ])->save();

        $this->announceSettled($stage, StageStatus::Skipped);
    }

    /**
     * There is nothing left to wait for on this stage.
     *
     * One event for completed and skipped alike: the only listener cares that
     * the work is over, and carrying the status lets anybody who needs the
     * difference have it.
     */
    private function announceSettled(JourneyStage $stage, StageStatus $status): void
    {
        Event::dispatch(new JourneyStageSettled(
            (int) $stage->getKey(),
            (int) $stage->service_journey_id,
            $stage->journey?->branchId() ?? 0,
            $status->value,
        ));
    }

    /**
     * How long this stage is expected to hold its resources.
     *
     *     now  →  now + appointment_item.duration_minutes
     *
     * The BOOKED duration from now, which is the best estimate available at the
     * moment somebody presses start, and the only one available without
     * inventing a scheduling policy.
     *
     * IT IS AN ADMISSION CHECK, NOT A COMPLETION TIME. Nothing writes it down.
     * The actual interval is `assigned_at → released_at` and is stamped when
     * the stage really ends — a service that overruns produces a longer
     * interval than the window it was admitted on, and that is the truth of
     * what happened rather than a discrepancy to reconcile.
     */
    private function expectedWindow(JourneyStage $stage, CarbonImmutable $now): TimeWindow
    {
        return TimeWindow::of($now, $stage->durationMinutes());
    }

    /**
     * The branch this stage is happening at, for the walk-in allocator.
     *
     * Null when there is none to find, which the planner treats as "allocate
     * nothing" rather than guessing at a branch — a resource belongs to exactly
     * one, and the wrong one would be worse than none.
     */
    private function branch(int $branchId): ?Branch
    {
        if ($branchId <= 0) {
            return null;
        }

        $branch = Branch::query()->find($branchId);

        return $branch instanceof Branch ? $branch : null;
    }

    /**
     * @param  array{reason?: string|null}  $options
     */
    private function reason(array $options): ?string
    {
        $reason = $options['reason'] ?? null;

        if (! is_string($reason)) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }

    private function verb(StageStatus $target): string
    {
        return match ($target) {
            StageStatus::InService => 'started',
            StageStatus::Completed => 'completed',
            StageStatus::Skipped => 'skipped',
            StageStatus::Waiting => 'reset',
        };
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(JourneyStage $stage, StageStatus $target, User $actingUser): void
    {
        $permission = match ($target) {
            StageStatus::InService => Permission::JourneyStageStart,
            // Skipping is the same operator closing the same stage, so it
            // rides the same grant rather than a thirteenth permission code
            // nothing would ever distinguish (§30).
            StageStatus::Completed, StageStatus::Skipped => Permission::JourneyStageComplete,
            StageStatus::Waiting => Permission::JourneyManage,
        };

        if (! $actingUser->hasPermission($permission)) {
            throw new AuthorizationException('You may not change that stage.');
        }

        $branchId = $stage->journey?->branchId();

        if ($branchId !== null && ! $actingUser->canAccessBranch((int) $branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
