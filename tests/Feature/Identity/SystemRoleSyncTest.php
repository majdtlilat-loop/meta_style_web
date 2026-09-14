<?php

declare(strict_types=1);

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Authorization\SystemRoleSynchroniser;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| System role synchronisation
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-032.
|
| Owner is a set of explicit grants, never an `if ($user->is_owner) return true`
| bypass (ADR-029). That is the right design and it has one consequence: a
| release that adds a permission leaves every center provisioned before it with
| an Owner who silently cannot use the new feature. Nothing errors. Nothing
| logs. The owner just finds a button that does nothing.
|
| `metastyle:roles:sync` closes that gap on deploy, and these tests are what
| keep it honest — including the parts that matter most when it goes wrong:
| one center's failure must not touch another, and the tenant context must
| never survive a tenant.
|
*/

/**
 * Simulates a center provisioned before a permission existed.
 *
 * The catalog is a PHP enum, so a genuinely new case cannot be introduced at
 * runtime. Removing one from a tenant's Owner role produces the identical
 * state: a role that is missing a permission the catalog now contains.
 */
function forgetOwnerPermission(Tenant $tenant, Permission $permission): void
{
    test()->asCenter($tenant, function () use ($permission): void {
        $owner = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();

        $owner->permissions()->where('permission', $permission->value)->delete();
    });
}

/**
 * @return list<string>
 */
function ownerPermissions(Tenant $tenant): array
{
    return test()->asCenter($tenant, fn (): array => Role::query()
        ->where('key', SystemRole::Owner->value)
        ->firstOrFail()
        ->permissionCodes());
}

function tenantModelFor(Tenant $tenant): TenantModel
{
    return TenantModel::query()->findOrFail($tenant->id);
}

it('gives an existing owner a permission the catalog gained since provisioning', function (): void {
    $center = $this->registerCenter('Sync Alpha', 'owner@syncalpha.test');

    forgetOwnerPermission($center['tenant'], Permission::AuditView);

    expect(ownerPermissions($center['tenant']))->not->toContain(Permission::AuditView->value);

    $result = app(SystemRoleSynchroniser::class)->synchronise(tenantModelFor($center['tenant']));

    expect($result->isSynced())->toBeTrue()
        ->and($result->permissionsAdded)->toBe(1)
        ->and(ownerPermissions($center['tenant']))->toContain(Permission::AuditView->value);

    // And the owner can actually use it — a row in role_permissions that the
    // permission check does not see would be worse than the gap it replaced.
    $owner = $this->ownerOf($center['tenant']);

    $allowed = $this->asCenter($center['tenant'], function () use ($owner): bool {
        $owner->forgetPermissionCache();

        return $owner->hasPermission(Permission::AuditView);
    });

    expect($allowed)->toBeTrue();
});

it('is idempotent: a second run changes nothing', function (): void {
    $center = $this->registerCenter('Sync Beta', 'owner@syncbeta.test');

    forgetOwnerPermission($center['tenant'], Permission::RoleDelete);

    $synchroniser = app(SystemRoleSynchroniser::class);
    $model = tenantModelFor($center['tenant']);

    $first = $synchroniser->synchronise($model);
    $second = $synchroniser->synchronise($model);
    $third = $synchroniser->synchronise($model);

    expect($first->changedAnything())->toBeTrue()
        ->and($second->changedAnything())->toBeFalse()
        ->and($third->changedAnything())->toBeFalse()
        ->and($second->rolesCreated)->toBe(0)
        ->and($second->permissionsAdded)->toBe(0);

    // No duplicate grants, and no duplicate roles.
    $counts = $this->asCenter($center['tenant'], fn (): array => [
        'duplicate_grants' => DB::connection('tenant')
            ->table('role_permissions')
            ->select('role_id', 'permission')
            ->groupBy('role_id', 'permission')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count(),
        'roles' => Role::query()->count(),
    ]);

    expect($counts['duplicate_grants'])->toBe(0)
        ->and($counts['roles'])->toBe(count(SystemRole::cases()));
});

it('leaves a center\'s own roles and assignments alone', function (): void {
    $center = $this->registerCenter('Sync Gamma', 'owner@syncgamma.test');

    // A role the center made for itself, with a deliberately narrow grant.
    $customId = $this->asCenter($center['tenant'], function (): int {
        /** @var Role $role */
        $role = Role::query()->create([
            'key' => 'front-desk-evening',
            'name' => TranslatedText::make('en', 'Front desk (evening)'),
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::StaffView]);

        return (int) $role->id;
    });

    // And a deliberate narrowing of a system role the center does not want wide.
    $this->asCenter($center['tenant'], function (): void {
        Role::query()->where('key', SystemRole::Manager->value)
            ->firstOrFail()
            ->permissions()
            ->where('permission', Permission::StaffCreate->value)
            ->delete();
    });

    forgetOwnerPermission($center['tenant'], Permission::SettingsView);

    app(SystemRoleSynchroniser::class)->synchronise(tenantModelFor($center['tenant']));

    $after = $this->asCenter($center['tenant'], fn (): array => [
        'custom' => Role::query()->findOrFail($customId)->permissionCodes(),
        'custom_is_system' => (bool) Role::query()->findOrFail($customId)->is_system,
        'manager' => Role::query()->where('key', SystemRole::Manager->value)->firstOrFail()->permissionCodes(),
        'owner_holders' => DB::connection('tenant')->table('user_roles')->count(),
    ]);

    // The center's own role is untouched — not widened, not re-keyed, not
    // promoted to a system role.
    expect($after['custom'])->toBe([Permission::StaffView->value])
        ->and($after['custom_is_system'])->toBeFalse()
        // A center that took a permission away from Manager meant it. Only
        // Owner is defined as "everything".
        ->and($after['manager'])->not->toContain(Permission::StaffCreate->value)
        // Who holds which role is the center's business, and sync never touches it.
        ->and($after['owner_holders'])->toBe(1)
        // Owner, though, is brought back up to the catalog.
        ->and(ownerPermissions($center['tenant']))->toContain(Permission::SettingsView->value);
});

it('isolates a failing tenant from the rest of the run', function (): void {
    $healthy = $this->registerCenter('Sync Healthy', 'owner@synchealthy.test');
    $broken = $this->registerCenter('Sync Broken', 'owner@syncbroken.test');

    forgetOwnerPermission($healthy['tenant'], Permission::AuditView);
    forgetOwnerPermission($broken['tenant'], Permission::AuditView);

    // Point the broken tenant at a database that does not exist. This is the
    // realistic shape of the failure: the control plane says the tenant is
    // provisioned, and its database is gone.
    $brokenModel = tenantModelFor($broken['tenant']);
    $realDatabase = (string) $brokenModel->tenancy_db_name;
    $brokenModel->forceFill(['tenancy_db_name' => 'ms_test_missing_database'])->save();

    // Broken first, so a leaked context or an abandoned transaction would
    // corrupt the healthy tenant that follows.
    $results = app(SystemRoleSynchroniser::class)->synchroniseMany([
        $brokenModel,
        tenantModelFor($healthy['tenant']),
    ]);

    expect($results)->toHaveCount(2)
        ->and($results[0]->isFailed())->toBeTrue()
        ->and($results[0]->error)->toBeString()->not->toBeEmpty()
        // The run continued, and the healthy tenant got its permission.
        ->and($results[1]->isSynced())->toBeTrue()
        ->and($results[1]->permissionsAdded)->toBe(1);

    $brokenModel->forceFill(['tenancy_db_name' => $realDatabase])->save();

    expect(ownerPermissions($healthy['tenant']))->toContain(Permission::AuditView->value)
        // The broken tenant's real database was never written to.
        ->and(ownerPermissions($broken['tenant']))->not->toContain(Permission::AuditView->value);
});

it('leaves no tenant bound after a run, successful or not', function (): void {
    $healthy = $this->registerCenter('Sync Context', 'owner@synccontext.test');
    $broken = $this->registerCenter('Sync Context Broken', 'owner@synccontextbroken.test');

    $brokenModel = tenantModelFor($broken['tenant']);
    $realDatabase = (string) $brokenModel->tenancy_db_name;
    $brokenModel->forceFill(['tenancy_db_name' => 'ms_test_missing_database'])->save();

    $context = app(TenantContext::class);

    expect($context->isBound())->toBeFalse();

    app(SystemRoleSynchroniser::class)->synchroniseMany([
        tenantModelFor($healthy['tenant']),
        $brokenModel,
    ]);

    // The failure came last on purpose: an exception escaping the tenant
    // closure without a `finally` would leave the context bound right here, and
    // whatever ran next would silently address the wrong database.
    expect($context->isBound())->toBeFalse()
        ->and($context->id())->toBeNull();

    // Fail closed, not open: with no tenant bound there is no tenant
    // connection at all (docs/02-TENANCY.md §4).
    expect(fn () => DB::connection('tenant')->getPdo())
        ->toThrow(InvalidArgumentException::class, 'Database connection [tenant] not configured.');

    $brokenModel->forceFill(['tenancy_db_name' => $realDatabase])->save();
});

it('skips tenants that were never fully provisioned', function (): void {
    $center = $this->registerCenter('Sync Unprovisioned', 'owner@syncunprov.test');

    $model = tenantModelFor($center['tenant']);
    $model->forceFill(['provisioning_status' => 'pending'])->save();

    $result = app(SystemRoleSynchroniser::class)->synchronise($model);

    // Skipped, not failed: there is nothing wrong here, the center simply has
    // no schema to synchronise yet. Reporting it as a failure would train
    // operators to ignore the command's exit code.
    expect($result->isSkipped())->toBeTrue()
        ->and($result->isFailed())->toBeFalse()
        ->and($result->changedAnything())->toBeFalse();

    $model->forceFill(['provisioning_status' => 'completed'])->save();
});

it('exposes the sync through metastyle:roles:sync', function (): void {
    $center = $this->registerCenter('Sync Command', 'owner@synccommand.test');

    forgetOwnerPermission($center['tenant'], Permission::BranchView);

    $this->artisan('metastyle:roles:sync', ['--all' => true])->assertSuccessful();

    expect(ownerPermissions($center['tenant']))->toContain(Permission::BranchView->value)
        ->and(app(TenantContext::class)->isBound())->toBeFalse();
});

it('fails the command when a tenant fails, so a deploy notices', function (): void {
    $broken = $this->registerCenter('Sync Command Broken', 'owner@synccmdbroken.test');

    $model = tenantModelFor($broken['tenant']);
    $realDatabase = (string) $model->tenancy_db_name;
    $model->forceFill(['tenancy_db_name' => 'ms_test_missing_database'])->save();

    $this->artisan('metastyle:roles:sync', ['--tenant' => $model->getTenantKey()])->assertFailed();

    $model->forceFill(['tenancy_db_name' => $realDatabase])->save();
});

it('gives an existing owner every Phase 4 permission after a sync', function (): void {
    $center = $this->registerCenter('Phase Four', 'owner@phasefour.test');

    // The exact scenario ADR-032 exists for: a center provisioned before the
    // release that added branch/catalog/menu/media permissions.
    $phaseFour = [
        Permission::BranchManage,
        Permission::DepartmentView, Permission::DepartmentManage,
        Permission::CategoryView, Permission::CategoryManage,
        Permission::ServiceView, Permission::ServiceCreate,
        Permission::ServiceUpdate, Permission::ServiceArchive,
        Permission::MenuView, Permission::MenuManage,
        Permission::MediaUpload,
        Permission::SettingsManage,
    ];

    foreach ($phaseFour as $permission) {
        forgetOwnerPermission($center['tenant'], $permission);
    }

    $before = ownerPermissions($center['tenant']);

    foreach ($phaseFour as $permission) {
        expect($before)->not->toContain($permission->value);
    }

    $result = app(SystemRoleSynchroniser::class)->synchronise(tenantModelFor($center['tenant']));

    expect($result->isSynced())->toBeTrue()
        ->and($result->permissionsAdded)->toBe(count($phaseFour));

    $after = ownerPermissions($center['tenant']);

    foreach ($phaseFour as $permission) {
        expect($after)->toContain($permission->value);
    }

    // And the owner can actually use them, not merely hold rows.
    $owner = $this->ownerOf($center['tenant']);

    $allowed = $this->asCenter($center['tenant'], function () use ($owner): bool {
        $owner->forgetPermissionCache();

        return $owner->hasPermission(Permission::ServiceCreate)
            && $owner->hasPermission(Permission::MenuManage)
            && $owner->hasPermission(Permission::MediaUpload);
    });

    expect($allowed)->toBeTrue();
});

it('keeps non-owner system roles at the access their release gave them', function (): void {
    $center = $this->registerCenter('Role Shapes', 'owner@roleshapes.test');

    $permissions = $this->asCenter($center['tenant'], fn (): array => [
        'manager' => Role::query()->where('key', SystemRole::Manager->value)->firstOrFail()->permissionCodes(),
        'host' => Role::query()->where('key', SystemRole::Host->value)->firstOrFail()->permissionCodes(),
        'cashier' => Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail()->permissionCodes(),
    ]);

    // A manager runs the place: staff, catalog, menu.
    expect($permissions['manager'])->toContain(Permission::ServiceCreate->value)
        ->toContain(Permission::MenuManage->value)
        ->toContain(Permission::MediaUpload->value)
        // But not the audit trail — a manager who can rewrite history is not
        // a control.
        ->not->toContain(Permission::AuditView->value);

    // Reception and the till read the catalog and change none of it.
    expect($permissions['host'])->toContain(Permission::ServiceView->value)
        ->not->toContain(Permission::ServiceUpdate->value);

    expect($permissions['cashier'])->toContain(Permission::ServiceView->value)
        ->not->toContain(Permission::ServiceCreate->value)
        ->not->toContain(Permission::MenuManage->value);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
