<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Application\StaffAccountFactory;
use App\Modules\Employees\Application\StaffGuard;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives an existing member of staff a login.
 *
 * The same identity rules as adding a person with a login
 * ({@see StaffAccountFactory}): a valid phone unique in the center, an optional
 * unique email, only roles whose every permission the actor holds, and no
 * password — an activation link, returned once, for the person to set their
 * own. The account's branch scope starts as the branches the person works at.
 */
final class GrantEmployeeLogin
{
    public function __construct(
        private readonly StaffAccountFactory $accounts,
        private readonly ManageStaffActivation $activation,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<int>  $roleIds
     * @return array{user: User, activation_token: string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Employee $employee, ?string $phone, ?string $email, array $roleIds, User $actingUser): array
    {
        if (! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException(__('manager_staff.errors.access_denied'));
        }

        StaffGuard::assertReaches($actingUser, $employee);

        if ($employee->user_id !== null) {
            throw ValidationException::withMessages(['login' => __('manager_staff.errors.has_login')]);
        }

        if (! $employee->status->isActive()) {
            throw ValidationException::withMessages(['login' => __('manager_staff.errors.inactive')]);
        }

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $this->accounts->assertCanGrantRoles($roleIds, $actingUser);

        $parsed = $this->accounts->requirePhone($phone);
        $email = $this->accounts->normaliseEmail($email);

        /** @var array{user: User, activation_token: string} $result */
        $result = DB::connection('tenant')->transaction(function () use ($employee, $parsed, $email, $roleIds, $actingUser): array {
            // Locked so two managers granting a login at once cannot both
            // link an account to the same person.
            $locked = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->user_id !== null) {
                throw ValidationException::withMessages(['login' => __('manager_staff.errors.has_login')]);
            }

            $user = $this->accounts->create($employee->name->get(), $parsed, $email, $employee->branchIds(), $roleIds);

            $locked->forceFill(['user_id' => $user->getKey()])->save();
            $employee->setRawAttributes($locked->getAttributes(), true);

            return ['user' => $user, 'activation_token' => $this->activation->issue($user, $actingUser)];
        });

        $this->audit->record(new AuditEvent(
            action: 'employees.employee.login_granted',
            category: AuditCategory::Security,
            actor: Actor::staff($actingUser),
            severity: AuditSeverity::Notice,
            targetType: Employee::class,
            targetId: $employee->uuid,
            targetLabel: (string) $employee->name,
            // Identifiers only: never the phone or email themselves.
            after: ['has_login' => true, 'roles' => $roleIds, 'has_email' => $email !== null],
        ));

        return $result;
    }
}
