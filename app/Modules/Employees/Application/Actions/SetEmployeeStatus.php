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
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Employees\Application\StaffGuard;
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
 * Three accounts are never switched off here: the owner's, the actor's own (a
 * manager who disables themselves has locked a center out of its own staff
 * page), and one holding permissions the actor does not.
 *
 * Status decides who is bookable, so the change takes the branch lock of every
 * branch the person works at (ADR-047). Existing appointments are untouched.
 *
 * The employee record itself is never deleted — historical bookings, invoices
 * and audit entries must keep resolving to a name.
 */
final class SetEmployeeStatus
{
    public function __construct(
        private readonly RevokeApiTokens $revokeTokens,
        private readonly ManageStaffActivation $activation,
        private readonly BranchLock $lock,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(Employee $employee, EmployeeStatus $status, User $actingUser): Employee
    {
        if (! $actingUser->hasPermission(Permission::StaffDeactivate)) {
            throw new AuthorizationException(__('manager_staff.errors.status_denied'));
        }

        StaffGuard::assertReaches($actingUser, $employee);

        /** @var User|null $user */
        $user = $employee->user;

        if ($user?->is_owner === true) {
            throw new AuthorizationException(__('manager_staff.errors.owner_protected'));
        }

        if ($user !== null && (int) $user->getKey() === (int) $actingUser->getKey()) {
            throw new AuthorizationException(__('manager_staff.errors.self_status'));
        }

        if ($user !== null) {
            StaffGuard::assertNotOutranked($user, $actingUser);
        }

        $before = $employee->status->value;
        $branchIds = $employee->branchIds();

        DB::connection('tenant')->transaction(function () use ($employee, $status, $user, $actingUser, $branchIds): void {
            $this->lock->acquire($branchIds);

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
