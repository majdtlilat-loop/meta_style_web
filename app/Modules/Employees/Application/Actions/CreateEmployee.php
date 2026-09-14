<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds a member of staff, optionally with a login.
 *
 * The two halves are separate on purpose. A center may want its stylists
 * listed and assigned to branches with no system access at all; giving every
 * one of them an account they never use is an attack surface, not a feature.
 *
 * When a login IS wanted, no password is set here. An activation link is issued
 * instead, so the creating manager never holds the new person's credential.
 */
final class CreateEmployee
{
    public function __construct(
        private readonly ManageStaffActivation $activation,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{employee: Employee, user: User|null, activation_token: string|null}
     */
    public function __invoke(NewEmployee $input, User $actingUser): array
    {
        $this->authorize($input, $actingUser);

        /** @var array{employee: Employee, user: User|null, activation_token: string|null} $result */
        $result = DB::connection('tenant')->transaction(function () use ($input, $actingUser): array {
            $user = $input->wantsLogin() ? $this->createUser($input, $actingUser) : null;

            /** @var Employee $employee */
            $employee = Employee::query()->create([
                'name' => TranslatedText::fromArray($input->name),
                'status' => $input->status,
                'user_id' => $user?->getKey(),
            ]);

            $employee->branches()->sync($input->branchIds);

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
                'branches' => $input->branchIds,
                'has_login' => $result['user'] !== null,
                'roles' => $input->roleIds,
            ],
        ));

        return $result;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(NewEmployee $input, User $actingUser): void
    {
        // Permission and branch scope are separate gates, and both must pass.
        // A manager scoped to one branch must not be able to staff another.
        if (! $actingUser->hasPermission(Permission::StaffCreate)) {
            throw new AuthorizationException('You may not add staff.');
        }

        $scope = $actingUser->branchScope();

        foreach ($input->branchIds as $branchId) {
            if (! $scope->allows($branchId)) {
                throw new AuthorizationException('You may not add staff to that branch.');
            }
        }

        if ($input->roleIds !== [] && ! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException('You may not assign roles.');
        }

        $this->assertCanGrantRoles($input->roleIds, $actingUser);
    }

    /**
     * Prevents privilege escalation through role assignment.
     *
     * Without this, a manager who may add staff could create an account with
     * the Owner role and then sign in as it. Nobody may grant a permission
     * they do not themselves hold.
     *
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     */
    private function assertCanGrantRoles(array $roleIds, User $actingUser): void
    {
        if ($roleIds === []) {
            return;
        }

        $held = $actingUser->permissions();

        /** @var list<Role> $roles */
        $roles = Role::query()->whereIn('id', $roleIds)->get()->all();

        foreach ($roles as $role) {
            foreach ($role->permissionCodes() as $code) {
                if (! in_array($code, $held, true)) {
                    throw new AuthorizationException(
                        'You may not assign a role that grants permissions you do not have.'
                    );
                }
            }
        }
    }

    private function createUser(NewEmployee $input, User $actingUser): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => $input->displayName(),
            'email' => $input->email,
            'phone' => $input->phone,
            // No password: the account exists but cannot sign in until the
            // person redeems their activation link.
            'password' => null,
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => false,
        ]);

        $user->syncBranchScope($input->branchIds);

        if ($input->roleIds !== []) {
            $user->roles()->sync($input->roleIds);
        }

        unset($actingUser);

        return $user;
    }

    private function actor(User $user): Actor
    {
        return new Actor(ActorType::Staff, AuditSource::Web, (string) $user->getKey(), $user->name);
    }
}
