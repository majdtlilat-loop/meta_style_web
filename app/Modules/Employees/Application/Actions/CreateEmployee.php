<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Employees\Application\StaffAccountFactory;
use App\Modules\Employees\Application\StaffBranches;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a member of staff, optionally with a login.
 *
 * The two halves are separate on purpose. A center may want its stylists
 * listed and assigned to branches with no system access at all; giving every
 * one of them an account they never use is an attack surface, not a feature.
 *
 * When a login IS wanted, no password is set here. An activation link is issued
 * instead, so the creating manager never holds the new person's credential.
 *
 * Every center user ACCOUNT has a phone number (owner, manager, staff): a
 * login is refused without a valid one, whichever form or API asked for it.
 * An employee without a login has no account, so needs none. An email is
 * optional and unique in the center — a duplicate is a field error, never the
 * database's UNIQUE constraint surfacing as a 500.
 */
final class CreateEmployee
{
    public function __construct(
        private readonly ManageStaffActivation $activation,
        private readonly StaffAccountFactory $accounts,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{employee: Employee, user: User|null, activation_token: string|null}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(NewEmployee $input, User $actingUser): array
    {
        $branchIds = $this->authorize($input, $actingUser);

        $name = TranslatedText::fromArray($input->name);

        if ($name->isEmpty()) {
            throw ValidationException::withMessages(['name' => __('manager_staff.errors.name_required')]);
        }

        $phone = $input->wantsLogin() ? $this->accounts->requirePhone($input->phone) : null;
        $email = $phone !== null ? $this->accounts->normaliseEmail($input->email) : null;

        /** @var array{employee: Employee, user: User|null, activation_token: string|null} $result */
        $result = DB::connection('tenant')->transaction(function () use ($input, $name, $phone, $email, $branchIds, $actingUser): array {
            $user = $phone instanceof PhoneNumber
                ? $this->accounts->create($input->displayName(), $phone, $email, $branchIds, $input->roleIds)
                : null;

            /** @var Employee $employee */
            $employee = Employee::query()->create([
                'name' => $name,
                'status' => $input->status,
                'user_id' => $user?->getKey(),
            ]);

            $employee->branches()->sync($branchIds);

            $token = $user !== null ? $this->activation->issue($user, $actingUser) : null;

            return ['employee' => $employee, 'user' => $user, 'activation_token' => $token];
        });

        $this->audit->record(new AuditEvent(
            action: 'employees.employee.created',
            category: AuditCategory::Config,
            actor: $this->actor($actingUser),
            targetType: Employee::class,
            targetId: $result['employee']->uuid,
            targetLabel: (string) $result['employee']->name,
            after: [
                'status' => $input->status->value,
                'branches' => $branchIds,
                'has_login' => $result['user'] !== null,
                // Roles belong to a login. Without one nothing was granted,
                // and the trail must not claim otherwise.
                'roles' => $result['user'] !== null ? $input->roleIds : [],
            ],
        ));

        return $result;
    }

    /**
     * @return list<int> the validated branch assignment
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function authorize(NewEmployee $input, User $actingUser): array
    {
        // Permission and branch scope are separate gates, and both must pass.
        // A manager scoped to one branch must not be able to staff another.
        if (! $actingUser->hasPermission(Permission::StaffCreate)) {
            throw new AuthorizationException(__('manager_staff.errors.create_denied'));
        }

        $branchIds = StaffBranches::resolve($input->branchIds, $actingUser);

        if ($input->roleIds !== [] && ! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException(__('manager_staff.errors.roles_denied'));
        }

        // Without this, a manager who may add staff could create an account
        // with the Owner role and then sign in as it.
        $this->accounts->assertCanGrantRoles($input->roleIds, $actingUser);

        return $branchIds;
    }

    private function actor(User $user): Actor
    {
        return new Actor(ActorType::Staff, AuditSource::Web, (string) $user->getKey(), $user->name);
    }
}
