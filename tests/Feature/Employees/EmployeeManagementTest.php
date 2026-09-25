<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Models\StaffActivationToken;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\SetEmployeeServices;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Application\Actions\GrantEmployeeLogin;
use App\Modules\Employees\Application\Actions\ReissueActivationLink;
use App\Modules\Employees\Application\Actions\SetEmployeeStatus;
use App\Modules\Employees\Application\Actions\UpdateEmployee;
use App\Modules\Employees\Application\Actions\UpdateEmployeeLogin;
use App\Modules\Employees\Application\EmployeePresenter;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Domain\Data\EmployeeChanges;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Employee management — the Manager team page's Actions
|--------------------------------------------------------------------------
|
| docs/06-AUTH-ROLES-PERMISSIONS.md §4–5. Every rule here is enforced in an
| Action; the Livewire screens only draw what these allow.
|
*/

/**
 * A manager scoped to the given branches.
 *
 * @param  list<int>  $branchIds
 */
function emScopedManager(array $branchIds, string $email = 'scoped@x.test'): User
{
    /** @var User $user */
    $user = User::query()->create(['name' => 'Scoped', 'email' => $email, 'phone' => '+96477000'.random_int(10000, 99999), 'password' => 'secret-password-1', 'is_active' => true, 'all_branches' => false]);
    $user->roles()->sync([Role::query()->where('key', SystemRole::Manager->value)->firstOrFail()->id]);
    $user->syncBranchScope($branchIds);
    $user->forgetPermissionCache();

    return $user;
}

function emOwner(): User
{
    return User::query()->where('is_owner', true)->firstOrFail();
}

it('refuses a duplicate email as a field error, lower-cases it, and audits roles only with a login', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $cashier = Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail();

        $first = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Sara'], roleIds: [$cashier->id], email: '  Sara@Alpha.TEST ', phone: '+9647701230101'), $owner);

        expect($first['user']?->email)->toBe('sara@alpha.test');

        // The column is UNIQUE: before the fix this surfaced as a 500.
        expect(fn () => app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Twin'], email: 'SARA@alpha.test', phone: '+9647701230102'), $owner))
            ->toThrow(ValidationException::class);
        expect(User::query()->where('phone', '+9647701230102')->exists())->toBeFalse();

        // Roles asked for without a login grant nothing, and the trail says so.
        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Listed'], roleIds: []), $owner);
        $audit = TenantAuditLog::query()->where('action', 'employees.employee.created')->where('target_label', 'Listed')->firstOrFail();

        expect($audit->after['has_login'])->toBeFalse()
            ->and($audit->after['roles'])->toBe([]);
    });
});

it('makes a scoped manager place new staff in one of their own branches', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $main = Branch::main();
        $manager = emScopedManager([$main->id]);

        // Branchless staff would be invisible to the manager who created them.
        expect(fn () => app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Nowhere']), $manager))
            ->toThrow(ValidationException::class);

        $created = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Here'], branchIds: [$main->id]), $manager);

        expect($created['employee']->branchIds())->toBe([$main->id]);
    });
});

it('updates a name and branches under scope, keeps the login name in sync, and audits it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');
        $third = $this->seedBranch('Mansour');

        $created = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Ali'], branchIds: [$main->id], phone: '+9647701230111'), $owner);
        $employee = $created['employee'];

        app(UpdateEmployee::class)($employee, new EmployeeChanges(['en' => 'Ali Hassan', 'ar' => 'علي حسن'], [$main->id, $second->id]), $owner);

        $employee->refresh();
        expect($employee->name->get('ar'))->toBe('علي حسن')
            ->and($employee->branchIds())->toEqualCanonicalizing([$main->id, $second->id])
            ->and($created['user']?->refresh()->name)->toBe('Ali Hassan')
            ->and(TenantAuditLog::query()->where('action', 'employees.employee.updated')->exists())->toBeTrue();

        // A manager of main + second may not pull the person into a branch they
        // do not run, nor edit someone who also works elsewhere.
        $manager = emScopedManager([$main->id, $second->id]);

        expect(fn () => app(UpdateEmployee::class)($employee, new EmployeeChanges(['en' => 'Ali'], [$main->id, $third->id]), $manager))
            ->toThrow(AuthorizationException::class);

        app(UpdateEmployee::class)($employee, new EmployeeChanges(['en' => 'Ali'], [$main->id, $second->id, $third->id]), $owner);

        expect(fn () => app(UpdateEmployee::class)($employee->refresh(), new EmployeeChanges(['en' => 'Renamed'], [$main->id]), $manager))
            ->toThrow(AuthorizationException::class);

        // Archived branches are never newly assigned.
        $third->forceFill(['archived_at' => Carbon::now()])->save();
        $fresh = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'New']), $owner)['employee'];

        expect(fn () => app(UpdateEmployee::class)($fresh, new EmployeeChanges(['en' => 'New'], [$third->id]), $owner))
            ->toThrow(ValidationException::class);
    });
});

it('reissues an activation link only before activation, and never for the owner, oneself or a superior', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $created = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Noor'], phone: '+9647701230121'), $owner);
        $employee = $created['employee'];
        $first = (string) $created['activation_token'];

        $second = app(ReissueActivationLink::class)($employee, $owner);

        // The earlier link is revoked: one live link per account.
        expect($second)->not->toBe($first)
            ->and(StaffActivationToken::query()->usable()->count())->toBe(1)
            ->and(fn () => app(ManageStaffActivation::class)->redeem($first, 'a-brand-new-password-11'))->toThrow(Exception::class);

        app(ManageStaffActivation::class)->redeem($second, 'a-brand-new-password-11');

        // Activated: a new link would let the manager take the account over.
        expect(fn () => app(ReissueActivationLink::class)($employee->refresh(), $owner))->toThrow(ValidationException::class);

        // The owner's own employee row is never a target.
        $ownerRow = Employee::query()->create(['name' => TranslatedText::fromArray(['en' => 'Owner']), 'user_id' => $owner->id, 'status' => EmployeeStatus::Active]);
        expect(fn () => app(ReissueActivationLink::class)($ownerRow, $owner))->toThrow(AuthorizationException::class);

        // A pending account holding more than the actor cannot be re-linked by
        // them: activating it would be a way up.
        $manager = emScopedManager([Branch::main()->id], 'm-reissue@x.test');
        $boss = app(CreateEmployee::class)(new NewEmployee(
            name: ['en' => 'Boss'],
            branchIds: [Branch::main()->id],
            roleIds: [Role::query()->where('key', SystemRole::Owner->value)->firstOrFail()->id],
            phone: '+9647701230122',
        ), $owner);

        expect(fn () => app(ReissueActivationLink::class)($boss['employee'], $manager))->toThrow(AuthorizationException::class);
    });
});

it('corrects a login\'s phone and email only before activation, with fingerprints in the audit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $main = Branch::main();
        $created = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Rana'], branchIds: [$main->id], email: 'rana@x.test', phone: '+9647701230141'), $owner);
        $employee = $created['employee'];

        // Its own number is not "taken" by itself; another account's is.
        app(UpdateEmployeeLogin::class)($employee, '+9647701230142', 'Rana.New@X.test', $owner);
        $user = $employee->refresh()->user;

        expect($user?->phone)->toBe('+9647701230142')
            ->and($user?->email)->toBe('rana.new@x.test')
            ->and(fn () => app(UpdateEmployeeLogin::class)($employee, '+9647701234567', null, $owner))->toThrow(ValidationException::class);

        $entry = TenantAuditLog::query()->where('action', 'employees.employee.login_contact_changed')->firstOrFail();
        expect(json_encode([$entry->before, $entry->after]))->not->toContain('9647701230142')
            ->and(json_encode([$entry->before, $entry->after]))->not->toContain('rana.new@x.test');

        // Somebody holding more than the actor is out of reach.
        $manager = emScopedManager([$main->id], 'm-contact@x.test');
        $boss = app(CreateEmployee::class)(new NewEmployee(
            name: ['en' => 'Boss'],
            branchIds: [$main->id],
            roleIds: [Role::query()->where('key', SystemRole::Owner->value)->firstOrFail()->id],
            phone: '+9647701230143',
        ), $owner);
        expect(fn () => app(UpdateEmployeeLogin::class)($boss['employee'], '+9647701230144', null, $manager))->toThrow(AuthorizationException::class);

        // Once activated, the contact belongs to the person.
        app(ManageStaffActivation::class)->redeem((string) $created['activation_token'], 'a-brand-new-password-11');
        expect(fn () => app(UpdateEmployeeLogin::class)($employee->refresh(), '+9647701230145', null, $owner))->toThrow(ValidationException::class)
            ->and($employee->refresh()->user?->phone)->toBe('+9647701230142');
    });
});

it('grants a login to listed staff with the same identity rules, once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $main = Branch::main();
        $employee = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Huda'], branchIds: [$main->id]), $owner)['employee'];
        $host = Role::query()->where('key', SystemRole::Host->value)->firstOrFail();

        // The owner's phone is taken.
        expect(fn () => app(GrantEmployeeLogin::class)($employee, '+9647701234567', null, [], $owner))->toThrow(ValidationException::class);

        $result = app(GrantEmployeeLogin::class)($employee, '+9647701230131', 'Huda@X.test', [$host->id], $owner);

        expect($result['user']->password)->toBeNull()
            ->and($result['user']->email)->toBe('huda@x.test')
            ->and($result['user']->hasRole(SystemRole::Host->value))->toBeTrue()
            ->and(StaffActivationToken::hash($result['activation_token']))->toBe(StaffActivationToken::query()->where('user_id', $result['user']->id)->value('token_hash'))
            ->and(DB::connection('tenant')->table('user_branches')->where('user_id', $result['user']->id)->pluck('branch_id')->all())->toBe([$main->id])
            ->and($employee->refresh()->user_id)->toBe($result['user']->id);

        expect(fn () => app(GrantEmployeeLogin::class)($employee, '+9647701230132', null, [], $owner))->toThrow(ValidationException::class);

        // Nobody grants a role carrying permissions they lack.
        $manager = emScopedManager([$main->id], 'm-grant@x.test');
        $listed = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Listed'], branchIds: [$main->id]), $owner)['employee'];
        $ownerRole = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();

        expect(fn () => app(GrantEmployeeLogin::class)($listed, '+9647701230133', null, [$ownerRole->id], $manager))->toThrow(AuthorizationException::class)
            ->and($listed->refresh()->user_id)->toBeNull();
    });
});

it('refuses self-deactivation and deactivating a superior, and reactivates cleanly', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $main = Branch::main();
        $manager = emScopedManager([$main->id], 'm-status@x.test');
        $selfRow = Employee::query()->create(['name' => TranslatedText::fromArray(['en' => 'Me']), 'user_id' => $manager->id, 'status' => EmployeeStatus::Active]);
        $selfRow->branches()->sync([$main->id]);

        expect(fn () => app(SetEmployeeStatus::class)($selfRow->load('user'), EmployeeStatus::Inactive, $manager))
            ->toThrow(AuthorizationException::class, __('manager_staff.errors.self_status'));

        $boss = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Boss'], branchIds: [$main->id], roleIds: [Role::query()->where('key', SystemRole::Owner->value)->firstOrFail()->id], phone: '+9647701230141'), $owner);
        expect(fn () => app(SetEmployeeStatus::class)($boss['employee']->load('user'), EmployeeStatus::Inactive, $manager))
            ->toThrow(AuthorizationException::class);

        $staff = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Staff'], branchIds: [$main->id], phone: '+9647701230142'), $owner);
        app(SetEmployeeStatus::class)($staff['employee']->load('user'), EmployeeStatus::Inactive, $manager);

        expect($staff['user']?->refresh()->is_active)->toBeFalse()
            ->and(StaffActivationToken::query()->where('user_id', $staff['user']?->id)->usable()->count())->toBe(0);

        app(SetEmployeeStatus::class)($staff['employee']->refresh()->load('user'), EmployeeStatus::Active, $manager);

        expect($staff['employee']->refresh()->status)->toBe(EmployeeStatus::Active)
            ->and($staff['user']?->refresh()->is_active)->toBeTrue();
    });
});

it('sets the services a person performs from their side, under scope, never newly to a retired service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');
        $cut = $this->seedService('Haircut', 30, 15000);
        $shave = $this->seedService('Shave', 20, 8000);
        $retired = $this->seedService('Old service', 30, 10000);
        $retired->forceFill(['archived_at' => Carbon::now()])->save();

        $employee = app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Omar'], branchIds: [$main->id]), $owner)['employee'];

        app(SetEmployeeServices::class)($employee, [$cut->uuid, $shave->uuid], $owner);

        expect(DB::connection('tenant')->table('employee_service')->where('employee_id', $employee->id)->pluck('service_id')->all())
            ->toEqualCanonicalizing([$cut->id, $shave->id])
            ->and($cut->eligibleEmployees()->pluck('employees.id')->all())->toBe([$employee->id]);

        app(SetEmployeeServices::class)($employee, [$shave->uuid], $owner);
        expect(DB::connection('tenant')->table('employee_service')->where('employee_id', $employee->id)->pluck('service_id')->all())->toBe([$shave->id]);

        expect(fn () => app(SetEmployeeServices::class)($employee, [$retired->uuid], $owner))->toThrow(ValidationException::class);

        $outsider = emScopedManager([$second->id], 'm-services@x.test');
        expect(fn () => app(SetEmployeeServices::class)($employee, [], $outsider))->toThrow(AuthorizationException::class);
        expect(TenantAuditLog::query()->where('action', 'catalog.employee_services.updated')->count())->toBe(2);
    });
});

it('lists staff branch-scoped with search and filters, and presents contact through neutral keys', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = emOwner();
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');

        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Main Stylist', 'ar' => 'مصففة الرئيسي'], branchIds: [$main->id], email: 'main@x.test', phone: '+9647701230151'), $owner);
        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Karrada Barber'], branchIds: [$second->id]), $owner);
        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Floating']), $owner);

        $query = app(EmployeeQuery::class);
        $names = static fn ($page): array => collect($page->items())->map(static fn (Employee $e): string => $e->name->get('en'))->sort()->values()->all();

        expect($names($query->paginate([], $owner)))->toBe(['Floating', 'Karrada Barber', 'Main Stylist']);

        $manager = emScopedManager([$main->id], 'm-list@x.test');
        expect($names($query->paginate([], $manager)))->toBe(['Main Stylist'])
            ->and($query->find(Employee::query()->where('name->en', 'Karrada Barber')->value('uuid'), $manager))->toBeNull();

        expect($names($query->paginate(['search' => 'مصففة'], $owner)))->toBe(['Main Stylist'])
            ->and($names($query->paginate(['search' => '0770 123 0151'], $owner)))->toBe(['Main Stylist'])
            ->and($names($query->paginate(['login' => 'pending'], $owner)))->toBe(['Main Stylist'])
            ->and($names($query->paginate(['login' => 'none'], $owner)))->toBe(['Floating', 'Karrada Barber'])
            ->and($names($query->paginate(['branch' => $second->uuid], $owner)))->toBe(['Karrada Barber']);

        $row = EmployeePresenter::for($owner)->summary($query->find(Employee::query()->where('name->en', 'Main Stylist')->value('uuid'), $owner) ?? throw new RuntimeException);

        expect($row['contact_email'])->toBe('main@x.test')
            ->and($row['contact_phone'])->toBe('+964 7701230151')
            ->and($row['login'])->toBe('pending')
            ->and($row['can']['reissue'])->toBeTrue()
            ->and(array_key_exists('password', $row))->toBeFalse();

        // A cashier sees no management flags.
        /** @var User $cashier */
        $cashier = User::query()->create(['name' => 'Cash', 'email' => 'cash@x.test', 'is_active' => true, 'all_branches' => true]);
        $cashier->roles()->sync([Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail()->id]);
        $flags = EmployeePresenter::for($cashier)->summary(Employee::query()->with(['branches', 'user.roles'])->where('name->en', 'Main Stylist')->firstOrFail())['can'];

        expect(array_filter($flags))->toBe([])
            ->and($cashier->hasPermission(Permission::StaffUpdate))->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
