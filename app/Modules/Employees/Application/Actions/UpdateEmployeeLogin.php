<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Employees\Application\StaffAccountFactory;
use App\Modules\Employees\Application\StaffGuard;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects the phone or email of a staff login — ONLY before it is activated.
 *
 * A mistyped number found before the link is handed over is an ordinary fix.
 * After activation the contact details belong to the person: pointing a used
 * account's email at an address the manager controls, then asking for a
 * password reset, is an account takeover, so it is refused here rather than
 * merely hidden. The same guards as re-issuing the link apply (never the
 * owner, yourself, or an account holding more than you), and the same identity
 * rules as creating it (valid phone, unique phone and email in the center).
 *
 * The audit carries fingerprints, never the phone or email themselves.
 */
final class UpdateEmployeeLogin
{
    public function __construct(
        private readonly StaffAccountFactory $accounts,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Employee $employee, ?string $phone, ?string $email, User $actingUser): User
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

        StaffGuard::assertNotOutranked($user, $actingUser);

        if ($user->password !== null) {
            throw ValidationException::withMessages(['login' => __('manager_staff.errors.contact_locked')]);
        }

        $parsed = $this->accounts->requirePhone($phone, $user);
        $email = $this->accounts->normaliseEmail($email, $user);

        $before = ['phone' => Fingerprint::of($user->phone), 'email' => Fingerprint::of($user->email)];

        DB::connection('tenant')->transaction(function () use ($user, $parsed, $email): void {
            // Re-read under a lock: an activation redeemed a moment ago makes
            // the account the person's own.
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->password !== null) {
                throw ValidationException::withMessages(['login' => __('manager_staff.errors.contact_locked')]);
            }

            $locked->forceFill(['phone' => $parsed->e164, 'email' => $email])->save();
            $user->setRawAttributes($locked->getAttributes(), true);
        });

        $after = ['phone' => Fingerprint::of($user->phone), 'email' => Fingerprint::of($user->email)];

        if ($before !== $after) {
            $this->audit->record(new AuditEvent(
                action: 'employees.employee.login_contact_changed',
                category: AuditCategory::Security,
                actor: Actor::staff($actingUser),
                severity: AuditSeverity::Notice,
                targetType: Employee::class,
                targetId: $employee->uuid,
                targetLabel: (string) $employee->name,
                before: $before,
                after: $after,
            ));
        }

        return $user;
    }
}
