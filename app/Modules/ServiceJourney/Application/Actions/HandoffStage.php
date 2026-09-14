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
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyHandoff;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The customer moves from one part of the center to the next.
 *
 * "Ahmed has finished the laser session; the customer is going to the hammam,
 * where Sara will take them." That is one operational event with six facts in
 * it, and this writes all six plus who recorded it
 * (docs/13-ROADMAP.md Phase 7 §23).
 *
 * ## Two records, one transaction
 *
 * The stage being left is completed and the stage being entered begins waiting,
 * and a {@see JourneyHandoff} row ties them together. Half of that applied
 * would be a customer who has finished nothing and is waiting nowhere, so it is
 * one transaction (§47).
 *
 * ## The destination is a real stage
 *
 * Phase 7 routes along the services the customer actually booked, in order.
 * There is no workflow designer and no invented destination: the next stage is
 * the next booked service, and its department came from that service. A center
 * with a genuinely custom route is a Phase 8+ conversation (§22).
 */
final class HandoffStage
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly TransitionStage $transition,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        JourneyStage $from,
        User $actingUser,
        ?string $toStageUuid = null,
        ?string $note = null,
    ): JourneyHandoff {
        $this->entitlements->ensure('booking');

        /*
         * Loaded here rather than assumed. An Action that relies on its caller
         * having eager-loaded the right relations is an Action that issues a
         * query per row the first time somebody calls it from a loop — and
         * `loadMissing` costs nothing when the caller already did the work.
         */
        $from->loadMissing(['journey.appointment', 'item']);

        $this->authorize($from, $actingUser);

        $journey = $from->journey;

        if ($journey === null) {
            throw JourneyFailed::policy('That service does not belong to a visit.');
        }

        $to = $this->destination($from, $toStageUuid);

        if ($from->status === StageStatus::InService) {
            // Finishing the current service is part of handing the customer on.
            // Done through the transition Action so the timestamps, the
            // resource release and the audit entry all happen the one way.
            ($this->transition)($from, StageStatus::Completed, $actingUser);
            $from->refresh();
        }

        /** @var JourneyHandoff $handoff */
        $handoff = DB::connection('tenant')->transaction(
            function () use ($journey, $from, $to, $note, $actingUser): JourneyHandoff {
                /** @var JourneyHandoff $handoff */
                $handoff = JourneyHandoff::query()->create([
                    'service_journey_id' => $journey->getKey(),
                    'from_stage_id' => $from->getKey(),
                    'to_stage_id' => $to?->getKey(),
                    'from_employee_id' => $from->employee_id,
                    'to_employee_id' => $to?->employee_id,
                    'from_department_id' => $from->department_id,
                    'to_department_id' => $to?->department_id,
                    'note' => $this->note($note),
                    'actor_type' => 'staff',
                    'actor_id' => $actingUser->uuid,
                    'actor_label' => $actingUser->name,
                ]);

                return $handoff;
            }
        );

        $this->audit->record(new AuditEvent(
            action: 'journey.handoff',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: JourneyHandoff::class,
            targetId: $handoff->uuid,
            before: [
                'stage_uuid' => $from->uuid,
                'employee_id' => $from->employee_id,
                'department_id' => $from->department_id,
            ],
            after: [
                'stage_uuid' => $to?->uuid,
                'employee_id' => $to?->employee_id,
                'department_id' => $to?->department_id,
            ],
        ));

        return $handoff;
    }

    /**
     * Where the customer is going.
     *
     * Named explicitly, or the next stage still waiting. A handoff with no
     * destination is valid and means "finished here, nothing next" — which is
     * what the last service of a visit is.
     *
     * @throws JourneyFailed
     */
    private function destination(JourneyStage $from, ?string $toStageUuid): ?JourneyStage
    {
        if ($toStageUuid === null) {
            return JourneyStage::query()
                ->where('service_journey_id', $from->service_journey_id)
                ->where('position', '>', $from->position)
                ->where('status', StageStatus::Waiting->value)
                ->orderBy('position')
                ->first();
        }

        $to = JourneyStage::query()
            ->where('service_journey_id', $from->service_journey_id)
            ->where('uuid', $toStageUuid)
            ->first();

        if (! $to instanceof JourneyStage) {
            // Scoped to the same journey, so a uuid from another customer's
            // visit is "not found" rather than a cross-visit handoff.
            throw JourneyFailed::policy('That is not a service in this visit.');
        }

        if ($to->isTerminal()) {
            throw JourneyFailed::invalidTransition('That service is already finished.');
        }

        return $to;
    }

    private function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(JourneyStage $stage, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyStageReassign)) {
            throw new AuthorizationException('You may not hand a customer on.');
        }

        $branchId = $stage->journey?->branchId();

        if ($branchId !== null && ! $actingUser->canAccessBranch((int) $branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
