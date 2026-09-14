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
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\ServiceJourney\Application\JourneySnapshot;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Somebody else is doing this service.
 *
 * Ahmed was booked; Ahmed is running an hour behind; Sara takes the customer.
 * The stage records Sara — and the APPOINTMENT ITEM STILL RECORDS AHMED,
 * because that is what the customer was promised and what their confirmation
 * says. Overwriting it would destroy the planned/actual comparison every later
 * report is built on, and would quietly rewrite history to say the center
 * always meant to do this (docs/13-ROADMAP.md Phase 7 §§18, 24, 27).
 *
 * ## Eligibility is enforced, not advisory
 *
 * The new employee must be active, work at this branch, and be qualified for
 * the service. A stage handed to somebody who cannot perform it is either a
 * mistake or a decision the center should be making deliberately — and Phase 7
 * takes the stated preference: REJECT rather than add a privileged override
 * nobody has asked for. The seam for one is this method and a reason field
 * (§24).
 */
final class ReassignStageEmployee
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(JourneyStage $stage, string $employeeUuid, User $actingUser): JourneyStage
    {
        $this->entitlements->ensure('booking');

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
                'That service is already finished, so its employee cannot be changed.',
                ['status' => $stage->status->value],
            );
        }

        $employee = $this->employee($employeeUuid, $stage);
        $before = JourneySnapshot::stage($stage);

        DB::connection('tenant')->transaction(function () use ($stage, $employee): void {
            $stage->forceFill(['employee_id' => $employee->getKey()])->save();
        });

        $stage->refresh();

        $this->audit->record(new AuditEvent(
            action: 'journey.stage.employee_reassigned',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: JourneyStage::class,
            targetId: $stage->uuid,
            targetLabel: (string) $employee->name,
            before: $before,
            after: JourneySnapshot::stage($stage),
            meta: [
                // The booked employee, so the trail shows the divergence rather
                // than just the new value.
                'booked_employee_id' => $stage->item?->employee_id,
            ],
        ));

        return $stage;
    }

    /**
     * @throws JourneyFailed
     */
    private function employee(string $uuid, JourneyStage $stage): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->first();

        if (! $employee instanceof Employee) {
            throw JourneyFailed::policy('That team member does not exist.');
        }

        if ($employee->status !== EmployeeStatus::Active) {
            throw JourneyFailed::policy('That team member is not active.');
        }

        $branchId = $stage->journey->branchId();

        if (! in_array($branchId, $employee->branchIds(), true)) {
            throw JourneyFailed::policy('That team member does not work at this branch.');
        }

        $serviceId = $stage->serviceId();

        if ($serviceId !== null && ! $this->isEligible($employee, (int) $serviceId)) {
            /*
             * Refused, not overridden. Handing a laser session to somebody not
             * qualified for it is either a mistake worth catching or a decision
             * a center should take deliberately — and no center has asked for
             * the override yet, so building one would be guessing at its rules
             * and its reason field (§24).
             */
            throw JourneyFailed::policy('That team member is not qualified for this service.');
        }

        return $employee;
    }

    private function isEligible(Employee $employee, int $serviceId): bool
    {
        return DB::connection('tenant')
            ->table('employee_service')
            ->where('employee_id', $employee->getKey())
            ->where('service_id', $serviceId)
            ->exists();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(JourneyStage $stage, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyStageReassign)) {
            throw new AuthorizationException('You may not reassign work.');
        }

        $branchId = $stage->journey?->branchId();

        if ($branchId !== null && ! $actingUser->canAccessBranch((int) $branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
