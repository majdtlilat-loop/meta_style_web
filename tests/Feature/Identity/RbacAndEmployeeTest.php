<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Actions\AssignRolesToUser;
use App\Kernel\Authorization\Actions\UpdateRolePermissions;
use App\Kernel\Authorization\BranchScope;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Exceptions\InvalidActivationToken;
use App\Kernel\Identity\Models\StaffActivationToken;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Application\Actions\SetEmployeeStatus;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| RBAC and employees
|--------------------------------------------------------------------------
|
| docs/06-AUTH-ROLES-PERMISSIONS.md, docs/13-ROADMAP.md Phase 3 Parts F and G.
|
*/

// ------------------------------------------------------------------ rbac ---

it('gives the owner every permission as explicit grants, not a bypass', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $role = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();

        // Every permission the owner holds is a stored row. Nothing in the
        // authorization path says "if owner, return true" — which is what makes
        // the answer auditable and narrowable (ADR-029).
        expect($role->permissionCodes())->toEqualCanonicalizing(Permission::codes())
            ->and($owner->permissions())->toEqualCanonicalizing(Permission::codes())
            ->and($owner->hasPermission(Permission::StaffCreate))->toBeTrue();
    });
});

it('denies a permission the user has not been granted', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $cashierRole = Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail();

        /** @var User $cashier */
        $cashier = User::query()->create(['name' => 'Cashier', 'email' => 'c@x.test', 'is_active' => true]);
        $cashier->roles()->sync([$cashierRole->id]);

        expect($cashier->hasPermission(Permission::BranchView))->toBeTrue()
            ->and($cashier->hasPermission(Permission::StaffCreate))->toBeFalse();
    });
});

it('supports custom center roles', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        /** @var Role $role */
        $role = Role::query()->create([
            'key' => 'senior-stylist',
            'name' => TranslatedText::fromArray(['en' => 'Senior Stylist']),
            'is_system' => false,
        ]);

        app(UpdateRolePermissions::class)($role, [Permission::StaffView->value], $owner);

        /** @var User $user */
        $user = User::query()->create(['name' => 'Stylist', 'email' => 's@x.test', 'is_active' => true]);
        $user->roles()->sync([$role->id]);

        expect($user->hasPermission(Permission::StaffView))->toBeTrue()
            ->and($user->hasPermission(Permission::StaffCreate))->toBeFalse()
            ->and($role->isProtected())->toBeFalse();
    });
});

it('refuses to grant a permission the granter does not hold', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $managerRole = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();

        /** @var User $manager */
        $manager = User::query()->create(['name' => 'Manager', 'email' => 'm@x.test', 'is_active' => true]);
        $manager->roles()->sync([$managerRole->id]);

        /** @var Role $role */
        $role = Role::query()->create([
            'key' => 'custom',
            'name' => TranslatedText::fromArray(['en' => 'Custom']),
        ]);

        // A manager cannot escalate by writing a permission they lack into a
        // role and then assigning it to themselves.
        app(UpdateRolePermissions::class)($role, [Permission::AuditView->value], $manager);
    });
})->throws(AuthorizationException::class);

it('enforces branch scope alongside permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $main = Branch::query()->where('is_main', true)->firstOrFail();

        /** @var Branch $second */
        $second = Branch::query()->create(['name' => TranslatedText::fromArray(['en' => 'Second'])]);

        /** @var User $scoped */
        $scoped = User::query()->create(['name' => 'Scoped', 'email' => 'sc@x.test', 'is_active' => true]);
        $scoped->syncBranchScope([$main->id]);

        expect($scoped->canAccessBranch($main->id))->toBeTrue()
            // Permission alone is not authorization: this is the leak that
            // matters INSIDE a tenant.
            ->and($scoped->canAccessBranch($second->id))->toBeFalse()
            ->and($scoped->branchScope()->isUnrestricted())->toBeFalse();

        $owner = User::query()->where('is_owner', true)->firstOrFail();

        expect($owner->branchScope()->isUnrestricted())->toBeTrue()
            ->and($owner->canAccessBranch($second->id))->toBeTrue();
    });
});

it('withdraws every permission from a deactivated account', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $role = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();

        /** @var User $user */
        $user = User::query()->create(['name' => 'M', 'email' => 'm2@x.test', 'is_active' => true]);
        $user->roles()->sync([$role->id]);

        expect($user->hasPermission(Permission::StaffView))->toBeTrue();

        $user->forceFill(['is_active' => false])->save();

        // Checked on every request rather than only at login, so a session or
        // token outliving a deactivation stops working immediately.
        expect($user->hasPermission(Permission::StaffView))->toBeFalse()
            ->and($user->branchScope())->toEqual(BranchScope::none());
    });
});

it('protects the owner account from having its roles changed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $managerRole = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();

        /** @var User $other */
        $other = User::query()->create(['name' => 'Other', 'email' => 'o@x.test', 'is_active' => true]);
        $other->roles()->sync([Role::query()->where('key', SystemRole::Owner->value)->firstOrFail()->id]);

        // A center that strips Owner from its only owner locks itself out, and
        // there is no support tool yet to put it back.
        app(AssignRolesToUser::class)($owner, [$managerRole->id], $other);
    });
})->throws(AuthorizationException::class);

it('audits a role permission change with before and after', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $role = Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail();

        app(UpdateRolePermissions::class)($role, [
            Permission::BranchView->value,
            Permission::StaffView->value,
        ], $owner);

        $entry = TenantAuditLog::query()
            ->where('action', 'authorization.role.permissions_changed')
            ->firstOrFail();

        // Derived from the seeded role rather than hardcoded, so the assertion
        // stays true as the catalog grows — a literal list here would have to
        // be edited by every phase that adds a permission.
        $seeded = array_map(
            static fn (Permission $p): string => $p->value,
            SystemRole::Cashier->permissions(),
        );

        expect($entry->severity)->toBe('critical')
            ->and($entry->before['permissions'])->toEqualCanonicalizing($seeded)
            ->and($entry->after['permissions'])->toContain(Permission::StaffView->value)
            ->and($entry->after['permissions'])->toContain(Permission::BranchView->value);
    });
});

// ------------------------------------------------------------- employees ---

it('creates an employee with no login at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $branch = Branch::query()->where('is_main', true)->firstOrFail();

        $result = app(CreateEmployee::class)(
            new NewEmployee(name: ['en' => 'Ali'], branchIds: [$branch->id]),
            $owner,
        );

        // A center may want its stylists listed without giving every one of
        // them an account they never use.
        expect($result['user'])->toBeNull()
            ->and($result['activation_token'])->toBeNull()
            ->and($result['employee']->hasLogin())->toBeFalse()
            ->and((string) $result['employee']->name)->toBe('Ali')
            ->and($result['employee']->branchIds())->toBe([$branch->id]);
    });
});

it('creates an employee with a login and an activation link, never a password', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $branch = Branch::query()->where('is_main', true)->firstOrFail();

        $result = app(CreateEmployee::class)(
            new NewEmployee(name: ['en' => 'Sara'], branchIds: [$branch->id], email: 'sara@x.test', phone: '+9647701230001'),
            $owner,
        );

        expect($result['user'])->not->toBeNull()
            // No password is set: the manager never holds the new person's
            // credential.
            ->and($result['user']->password)->toBeNull()
            ->and($result['user']->canAuthenticate())->toBeFalse()
            ->and($result['activation_token'])->toBeString();

        // Only the hash is stored, so a leak of this table yields no tokens.
        $stored = StaffActivationToken::query()->firstOrFail();

        expect($stored->token_hash)->toBe(StaffActivationToken::hash((string) $result['activation_token']))
            ->and($stored->token_hash)->not->toBe($result['activation_token']);
    });
});

it('lets staff redeem an activation link exactly once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        $result = app(CreateEmployee::class)(
            new NewEmployee(name: ['en' => 'Sara'], email: 'sara@x.test', phone: '+9647701230002'),
            $owner,
        );

        $token = (string) $result['activation_token'];

        $user = app(ManageStaffActivation::class)->redeem($token, 'a-brand-new-password-99');

        expect($user->refresh()->canAuthenticate())->toBeTrue();

        // Single use: replaying the link must not work.
        expect(fn () => app(ManageStaffActivation::class)->redeem($token, 'another-password-99'))
            ->toThrow(InvalidActivationToken::class);
    });
});

it('refuses to let unauthorised staff create employees', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $cashierRole = Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail();

        /** @var User $cashier */
        $cashier = User::query()->create(['name' => 'Cashier', 'email' => 'c2@x.test', 'is_active' => true]);
        $cashier->roles()->sync([$cashierRole->id]);

        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'X']), $cashier);
    });
})->throws(AuthorizationException::class);

it('refuses to create staff in a branch outside the actor\'s scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $main = Branch::query()->where('is_main', true)->firstOrFail();

        /** @var Branch $other */
        $other = Branch::query()->create(['name' => TranslatedText::fromArray(['en' => 'Other'])]);

        $managerRole = Role::query()->where('key', SystemRole::Manager->value)->firstOrFail();

        /** @var User $manager */
        $manager = User::query()->create(['name' => 'M', 'email' => 'm3@x.test', 'is_active' => true]);
        $manager->roles()->sync([$managerRole->id]);
        $manager->syncBranchScope([$main->id]);

        app(CreateEmployee::class)(
            new NewEmployee(name: ['en' => 'X'], branchIds: [$other->id]),
            $manager,
        );
    });
})->throws(AuthorizationException::class);

it('cuts off access completely when an employee is deactivated', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        $created = app(CreateEmployee::class)(
            new NewEmployee(name: ['en' => 'Sara'], email: 'sara2@x.test', phone: '+9647701230003'),
            $owner,
        );

        /** @var User $user */
        $user = $created['user'];

        app(ManageStaffActivation::class)->redeem((string) $created['activation_token'], 'a-brand-new-password-99');
        $user->refresh()->createToken('device');

        expect($user->tokens()->count())->toBe(1);

        $employee = Employee::query()->with('user', 'branches')->findOrFail($created['employee']->id);

        app(SetEmployeeStatus::class)($employee, EmployeeStatus::Inactive, $owner);

        // Account disabled, tokens deleted, outstanding links revoked. Leaving
        // a live token behind is how a "removed" employee keeps reading the
        // customer list for another month.
        expect($user->refresh()->is_active)->toBeFalse()
            ->and($user->tokens()->count())->toBe(0)
            ->and(StaffActivationToken::query()->usable()->count())->toBe(0)
            ->and($employee->refresh()->status)->toBe(EmployeeStatus::Inactive);
    });
});

it('refuses to deactivate the owner', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        /** @var Employee $employee */
        $employee = Employee::query()->create([
            'name' => TranslatedText::fromArray(['en' => 'Owner']),
            'user_id' => $owner->id,
            'status' => EmployeeStatus::Active,
        ]);

        app(SetEmployeeStatus::class)(
            Employee::query()->with('user', 'branches')->findOrFail($employee->id),
            EmployeeStatus::Inactive,
            $owner,
        );
    });
})->throws(AuthorizationException::class);

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
