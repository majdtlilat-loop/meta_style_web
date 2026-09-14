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
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use App\Modules\ServiceJourney\Domain\StageResources;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Laser Machine 1 has stopped working. Move the customer to Machine 2.
 *
 * ## The old usage is CLOSED, not overwritten
 *
 *     Laser Machine 1   10:00 → 10:15   released, reason "device fault"
 *     Laser Machine 2   10:15 → …       open
 *
 * Overwriting `resource_id` in place would leave the record saying the customer
 * was on Machine 2 the whole time — which erases the fifteen minutes Machine 1
 * was in use and the fact that it failed. Device utilisation, fault history and
 * "what was this customer actually treated with" all need the intervals
 * (docs/13-ROADMAP.md Phase 7 corrections §5).
 *
 * ## The BOOKING is not rewritten
 *
 * `resource_reservations` still says Machine 1. The booking reserved Machine 1;
 * that is a true statement about the plan and pretending otherwise would hide
 * the divergence this whole distinction exists to record (§26, §36).
 *
 * ## Capacity is checked on the way in, BEFORE anything is closed
 *
 * Against other stages' actual holds — Machine 2 is no good if somebody else is
 * already on it — and against committed reservations, so a machine promised to
 * another customer at 10:30 refuses a swap at 10:20 whose remaining twenty
 * minutes would run into it ({@see StageResources}).
 *
 * The whole thing is ONE transaction with the branch lock held: validate, close
 * the old row, open the new one, or nothing happens at all. Closing first and
 * validating after would leave a customer physically in a room the system
 * believes is empty — the one outcome worse than a refused swap.
 *
 * And the other booking is never touched. If the capacity is promised, the
 * answer is a different resource or a refusal; a reservation is not something
 * an operational screen gets to take away because the room is convenient now.
 */
final class SwapStageResource
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly StageResources $resources,
        private readonly BranchLock $branches,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        JourneyStage $stage,
        string $fromResourceUuid,
        string $toResourceUuid,
        User $actingUser,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): JourneyStageResource {
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

        $this->authorize($stage, $actingUser);

        if ($stage->isTerminal()) {
            throw JourneyFailed::invalidTransition(
                'That service is already finished, so its resources cannot be changed.',
                ['status' => $stage->status->value],
            );
        }

        $open = $this->openUsage($stage, $fromResourceUuid);
        $replacement = $this->resource($toResourceUuid, $stage);

        if ((int) $replacement->getKey() === (int) $open->resource_id) {
            throw JourneyFailed::policy('That is the resource already in use.');
        }

        $window = $this->remainingWindow($stage, $now);
        $quantity = $open->quantity;
        $branchId = (int) ($stage->journey?->branchId() ?? 0);

        /** @var JourneyStageResource $opened */
        $opened = DB::connection('tenant')->transaction(
            function () use ($stage, $open, $replacement, $reason, $actingUser, $now, $window, $quantity, $branchId): JourneyStageResource {
                // The same lock booking takes, so the capacity read below
                // cannot race a reservation being committed (ADR-047).
                if ($branchId > 0) {
                    $this->branches->acquireOne($branchId);
                }

                /*
                 * PROVED FIRST. A refusal here rolls the transaction back with
                 * the old usage still open and unchanged, which is exactly what
                 * should happen: the customer has not moved, so the record must
                 * not say they have.
                 */
                $this->resources->assertFits($replacement, $quantity, $window, $stage);

                // Closed, never deleted. The interval it covered is the point.
                $open->forceFill([
                    'released_at' => $now,
                    'release_reason' => $this->reason($reason),
                ])->save();

                return $this->resources->open(
                    $stage,
                    $replacement,
                    $open->quantity,
                    $now,
                    (int) $actingUser->getKey(),
                );
            }
        );

        $this->audit->record(new AuditEvent(
            action: 'journey.stage.resource_swapped',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: JourneyStage::class,
            targetId: $stage->uuid,
            targetLabel: (string) $replacement->name,
            before: ['resource_id' => $open->resource_id, 'quantity' => $open->quantity],
            after: ['resource_id' => $replacement->getKey(), 'quantity' => $opened->quantity],
            meta: $reason === null ? [] : ['reason' => $this->reason($reason)],
        ));

        return $opened;
    }

    /**
     * @throws JourneyFailed
     */
    private function openUsage(JourneyStage $stage, string $resourceUuid): JourneyStageResource
    {
        $usage = JourneyStageResource::query()
            ->where('journey_stage_id', $stage->getKey())
            ->whereNull('released_at')
            ->whereHas('resource', fn ($q) => $q->where('uuid', $resourceUuid))
            ->first();

        if (! $usage instanceof JourneyStageResource) {
            throw JourneyFailed::policy('This service is not using that resource.');
        }

        return $usage;
    }

    /**
     * @throws JourneyFailed
     */
    private function resource(string $uuid, JourneyStage $stage): OperationalResource
    {
        $branchId = $stage->journey->branchId();

        $resource = OperationalResource::query()
            ->where('uuid', $uuid)
            ->where('branch_id', $branchId)
            ->first();

        if (! $resource instanceof OperationalResource) {
            throw JourneyFailed::policy('That resource is not available at this branch.');
        }

        // An archived resource cannot be swapped IN. Existing holds on one are
        // untouched — that is the promise archiving makes — but starting a new
        // hold on something the center has retired is a different thing (§12).
        if (! $resource->isBookable()) {
            throw JourneyFailed::policy('That resource is no longer in service.');
        }

        return $resource;
    }

    /**
     * How long the replacement is expected to be held.
     *
     *     now  →  service_started_at + appointment_item.duration_minutes
     *
     * The REMAINING booked duration — so a swap ten minutes into a thirty
     * minute treatment asks about the remaining twenty rather than a fresh
     * thirty, which would refuse machines that are genuinely free.
     *
     * A service already past its planned end asks about a minimal window rather
     * than an empty one, which `TimeWindow` refuses to construct. It is still a
     * real question: the machine has to be free NOW, whatever the plan said.
     *
     * Like the start check, this is an admission window and nothing more. It is
     * never written down; the actual interval runs to `released_at`.
     */
    private function remainingWindow(JourneyStage $stage, CarbonImmutable $now): TimeWindow
    {
        $minutes = $stage->durationMinutes();

        $startedAt = $stage->service_started_at === null
            ? $now
            : CarbonImmutable::parse($stage->service_started_at, 'UTC');

        $plannedEnd = $startedAt->addMinutes(max(1, $minutes));

        return $plannedEnd > $now
            ? new TimeWindow($now, $plannedEnd)
            : TimeWindow::of($now, 1);
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(JourneyStage $stage, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyStageReassign)) {
            throw new AuthorizationException('You may not change the resources a service is using.');
        }

        $branchId = $stage->journey?->branchId();

        if ($branchId !== null && ! $actingUser->canAccessBranch((int) $branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
