<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Actions\AssignRolesToUser;
use App\Kernel\Authorization\Actions\CreateRole;
use App\Kernel\Authorization\Actions\DeleteRole;
use App\Kernel\Authorization\Actions\RenameRole;
use App\Kernel\Authorization\Actions\SetUserBranchScope;
use App\Kernel\Authorization\Actions\UpdateRolePermissions;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Role lifecycle and access changes
|--------------------------------------------------------------------------
|
| docs/06-AUTH-ROLES-PERMISSIONS.md §4–5: custom roles are the only way to a
| custom permission set; nobody grants what they do not hold; the owner can
| never be locked out.
|
*/

function rmOwner(): User
{
    return User::query()->where('is_owner', true)->firstOrFail();
}

/**
 * @param  list<Permission>  $permissions
 * @param  list<int>|null  $branchIds  null = every branch
 */
function rmUserWith(array $permissions, string $email, ?array $branchIds = null): User
{
    /** @var Role $role */
    $role = Role::query()->create(['key' => 'rm-'.md5($email), 'name' => ['en' => 'Role for '.$email], 'is_system' => false]);
    $role->syncPermissions($permissions);

    /** @var User $user */
    $user = User::query()->create(['name' => $email, 'email' => $email, 'is_active' => true, 'all_branches' => $branchIds === null]);
    $user->roles()->sync([$role->id]);
    $user->syncBranchScope($branchIds ?? []);
    $user->forgetPermissionCache();

    return $user;
}

it('creates, renames and deletes a custom role, with names checked per language', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = rmOwner();

        $role = app(CreateRole::class)(['en' => 'Front desk', 'ar' => 'الاستقبال الأمامي'], [Permission::AppointmentView->value, Permission::CustomerView->value], $owner);

        expect($role->is_system)->toBeFalse()
            ->and($role->key)->toStartWith('custom-')
            ->and($role->permissionCodes())->toEqualCanonicalizing([Permission::AppointmentView->value, Permission::CustomerView->value])
            ->and(TenantAuditLog::query()->where('action', 'authorization.role.created')->exists())->toBeTrue();

        // Two roles nobody can tell apart are refused, case-insensitively.
        expect(fn () => app(CreateRole::class)(['en' => 'front DESK'], [], $owner))->toThrow(ValidationException::class)
            ->and(fn () => app(CreateRole::class)(['en' => '  '], [], $owner))->toThrow(ValidationException::class);

        app(RenameRole::class)($role, ['en' => 'Reception desk'], $owner);
        // A language not on the form keeps its stored text.
        expect($role->refresh()->name->get('en'))->toBe('Reception desk')
            ->and($role->name->get('ar'))->toBe('الاستقبال الأمامي');

        // Built-in roles keep their names and cannot be deleted.
        $manager = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();
        expect(fn () => app(RenameRole::class)($manager, ['en' => 'Boss'], $owner))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteRole::class)($manager, $owner))->toThrow(AuthorizationException::class);

        // Refused while anybody holds it — deleting would silently strip access.
        /** @var User $holder */
        $holder = User::query()->create(['name' => 'Holder', 'email' => 'holder@x.test', 'is_active' => true]);
        $holder->roles()->sync([$role->id]);

        expect(fn () => app(DeleteRole::class)($role, $owner))->toThrow(ValidationException::class)
            ->and(Role::query()->whereKey($role->id)->exists())->toBeTrue();

        $holder->roles()->sync([]);
        app(DeleteRole::class)($role, $owner);

        expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
            ->and(TenantAuditLog::query()->where('action', 'authorization.role.deleted')->value('severity'))->toBe('critical');
    });
});

it('checks role management permissions and refuses initial codes the creator lacks', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $creator = rmUserWith([Permission::RoleView, Permission::RoleCreate, Permission::RolePermissionsManage, Permission::CustomerView], 'creator@x.test');

        expect(fn () => app(CreateRole::class)(['en' => 'Sneaky'], [Permission::AuditView->value], $creator))->toThrow(AuthorizationException::class)
            // The whole creation rolled back with the refused grant.
            ->and(Role::query()->where('name->en', 'Sneaky')->exists())->toBeFalse();

        $viewer = rmUserWith([Permission::RoleView], 'viewer@x.test');
        $custom = app(CreateRole::class)(['en' => 'Custom'], [], rmOwner());

        expect(fn () => app(CreateRole::class)(['en' => 'Nope'], [], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RenameRole::class)($custom, ['en' => 'Nope'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DeleteRole::class)($custom, $viewer))->toThrow(AuthorizationException::class);
    });
});

it('checks only the ADDED codes when editing, and keeps the Owner role read-only', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $role = app(CreateRole::class)(['en' => 'Mixed'], [Permission::AuditView->value, Permission::CustomerView->value], rmOwner());
        $editor = rmUserWith([Permission::RolePermissionsManage, Permission::CustomerView, Permission::BranchView], 'editor@x.test');

        // The editor lacks audit.view, which the role already carries. They can
        // still add what they hold — and the code they lack stays as it was.
        app(UpdateRolePermissions::class)($role, [Permission::AuditView->value, Permission::CustomerView->value, Permission::BranchView->value], $editor);
        expect($role->refresh()->permissionCodes())->toEqualCanonicalizing([Permission::AuditView->value, Permission::CustomerView->value, Permission::BranchView->value]);

        // Nor can they take it away: a form that leaves it out narrows only
        // what the editor holds.
        app(UpdateRolePermissions::class)($role, [Permission::BranchView->value], $editor);
        expect($role->refresh()->permissionCodes())->toEqualCanonicalizing([Permission::AuditView->value, Permission::BranchView->value]);

        // Adding a code they do not hold is still refused.
        expect(fn () => app(UpdateRolePermissions::class)($role, [...$role->permissionCodes(), Permission::FinanceView->value], $editor))
            ->toThrow(AuthorizationException::class);

        // Owner means "everything"; removing role management from it is a lock-out.
        $ownerRole = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();
        expect(fn () => app(UpdateRolePermissions::class)($ownerRole, [Permission::StaffView->value], rmOwner()))
            ->toThrow(AuthorizationException::class)
            ->and($ownerRole->refresh()->permissionCodes())->toEqualCanonicalizing(Permission::codes());
    });
});

it('never lets the owner drop the Owner role, nor anyone change their own or a superior\'s roles', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = rmOwner();
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');
        $ownerRole = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();
        $managerRole = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();
        $hostRole = Role::query()->where('key', SystemRole::Host->value)->firstOrFail();

        expect(fn () => app(AssignRolesToUser::class)($owner, [$managerRole->id], $owner))->toThrow(AuthorizationException::class);
        app(AssignRolesToUser::class)($owner, [$ownerRole->id, $managerRole->id], $owner);
        expect($owner->refresh()->hasRole(SystemRole::Owner->value))->toBeTrue();

        $manager = rmUserWith(SystemRole::Manager->permissions(), 'mgr@x.test', [$main->id]);
        expect(fn () => app(AssignRolesToUser::class)($manager, [$hostRole->id], $manager))->toThrow(AuthorizationException::class);

        // Someone holding more than the actor is out of reach.
        $auditor = rmUserWith([Permission::AuditView], 'audit@x.test', [$main->id]);
        expect(fn () => app(AssignRolesToUser::class)($auditor, [$hostRole->id], $manager))->toThrow(AuthorizationException::class);

        // So is someone who also works at a branch outside the actor's scope.
        $elsewhere = rmUserWith([Permission::BranchView], 'else@x.test', [$main->id, $second->id]);
        expect(fn () => app(AssignRolesToUser::class)($elsewhere, [$hostRole->id], $manager))->toThrow(AuthorizationException::class);

        $local = rmUserWith([Permission::BranchView], 'local@x.test', [$main->id]);
        app(AssignRolesToUser::class)($local, [$hostRole->id], $manager);
        expect($local->refresh()->hasRole(SystemRole::Host->value))->toBeTrue();
    });
});

it('sets branch scope only within the actor\'s own reach', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = rmOwner();
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');

        $manager = rmUserWith(SystemRole::Manager->permissions(), 'scope-mgr@x.test', [$main->id]);
        $staff = rmUserWith([Permission::BranchView], 'scope-staff@x.test', [$main->id]);

        // Every branch — including future ones — only from someone who has it.
        expect(fn () => app(SetUserBranchScope::class)($staff, true, [], $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetUserBranchScope::class)($staff, false, [$second->id], $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetUserBranchScope::class)($staff, false, [], $manager))->toThrow(ValidationException::class)
            ->and(fn () => app(SetUserBranchScope::class)($manager, false, [$main->id], $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetUserBranchScope::class)($owner, false, [$main->id], $owner))->toThrow(AuthorizationException::class);

        app(SetUserBranchScope::class)($staff, true, [], $owner);
        expect($staff->refresh()->branchScope()->isUnrestricted())->toBeTrue();

        // Now the staff member reaches a branch the manager does not: out of reach.
        expect(fn () => app(SetUserBranchScope::class)($staff, false, [$main->id], $manager))->toThrow(AuthorizationException::class);

        app(SetUserBranchScope::class)($staff, false, [$second->id], $owner);
        expect($staff->refresh()->all_branches)->toBeFalse()
            ->and($staff->canAccessBranch($second->id))->toBeTrue()
            ->and($staff->canAccessBranch($main->id))->toBeFalse()
            ->and(TenantAuditLog::query()->where('action', 'authorization.user.scope_changed')->count())->toBe(2);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
