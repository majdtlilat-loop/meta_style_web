<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Application\StaffGuard;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Issues a fresh activation link for a login that has never been activated.
 *
 * ONLY before activation. Whoever holds an activation link can set the
 * account's password, so re-issuing one for an account somebody already uses
 * would let a manager take that account over — the person who forgot their
 * password uses "Forgot password", which reaches THEM, not the manager.
 *
 * For the same reason the account must hold nothing the manager does not
 * (StaffGuard::outranks): activating it themselves must not be a way up.
 *
 * `ManageStaffActivation::issue()` revokes every earlier link first and audits
 * the issue; the plaintext is returned once and stored nowhere.
 */
final class ReissueActivationLink
{
    public function __construct(private readonly ManageStaffActivation $activation) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Employee $employee, User $actingUser): string
    {
        if (! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException(__('manager_staff.errors.access_denied'));
        }

        StaffGuard::assertReaches($actingUser, $employee);

        $user = $employee->user;

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['login' => __('manager_staff.errors.no_login')]);
        }

        if ($user->is_owner || (int) $user->getKey() === (int) $actingUser->getKey()) {
            throw new AuthorizationException(__('manager_staff.errors.access_protected'));
        }

        if ($user->password !== null) {
            throw ValidationException::withMessages(['login' => __('manager_staff.errors.already_activated')]);
        }

        if (! $employee->status->isActive() || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => __('manager_staff.errors.inactive')]);
        }

        StaffGuard::assertNotOutranked($user, $actingUser);

        return $this->activation->issue($user, $actingUser);
    }
}
