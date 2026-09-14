<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Actions\RevokeApiTokens;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a member of staff.
 *
 * Deactivating cuts access immediately and completely: the linked account is
 * disabled, its API tokens are deleted, and any outstanding activation link is
 * revoked. Leaving a live token behind is how a "removed" employee keeps
 * reading the customer list for another month.
 *
 * The employee record itself is never deleted — historical bookings, invoices
 * and audit entries must keep resolving to a name.
 */
final class SetEmployeeStatus
{
    public function __construct(
        private readonly RevokeApiTokens $revokeTokens,
        private readonly ManageStaffActivation $activation,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(Employee $employee, EmployeeStatus $status, User $actingUser): Employee
    {
        if (! $actingUser->hasPermission(Permission::StaffDeactivate)) {
            throw new AuthorizationException('You may not change staff status.');
        }

        $scope = $actingUser->branchScope();

        foreach ($employee->branchIds() as $branchId) {
            if (! $scope->allows($branchId)) {
                throw new AuthorizationException('That member of staff is outside your branches.');
            }
        }

        /** @var User|null $user */
        $user = $employee->user;

        if ($user?->is_owner === true) {
            throw new AuthorizationException('The owner account cannot be deactivated.');
        }

        $before = $employee->status->value;

        DB::connection('tenant')->transaction(function () use ($employee, $status, $user, $actingUser): void {
            $employee->forceFill(['status' => $status])->save();

            if ($user === null) {
                return;
            }

            $user->forceFill(['is_active' => $status->isActive()])->save();

            if (! $status->isActive()) {
                ($this->revokeTokens)($user, 'employee deactivated', $this->actor($actingUser));
                $this->activation->revokeOutstanding($user, 'employee deactivated', $actingUser);
            }
        });

        $this->audit->record(new AuditEvent(
            action: $status->isActive() ? 'employees.employee.activated' : 'employees.employee.deactivated',
            category: AuditCategory::Security,
            actor: $this->actor($actingUser),
            severity: AuditSeverity::Notice,
            targetType: Employee::class,
            targetId: $employee->uuid,
            targetLabel: (string) $employee->name,
            before: ['status' => $before],
            after: ['status' => $status->value],
        ));

        return $employee;
    }

    private function actor(User $user): Actor
    {
        return new Actor(ActorType::Staff, AuditSource::Web, (string) $user->getKey(), $user->name);
    }
}
