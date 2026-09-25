<?php

declare(strict_types=1);

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Branches;
use App\Livewire\Center\Resources\AvailabilityBlocks;
use App\Livewire\Center\Resources\ResourceList;
use App\Livewire\Center\Roles;
use App\Livewire\Center\Staff;
use App\Livewire\Center\Staff\Access;
use App\Livewire\Center\Staff\Profile;
use App\Livewire\Center\Staff\Services;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager — team, roles, branches and resources pages
|--------------------------------------------------------------------------
|
| The screens only draw what the Actions allow; these pin the wiring, the
| data-loss fixes and the translations, in every interface language.
|
*/

it('renders team, roles, branches and resources in every interface language with no raw keys', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Sara', 'ar' => 'سارة'], branchIds: [Branch::main()->id], email: 'sara@alpha.test', phone: '+9647701230301'), $owner);

        $this->actingAs($owner);

        foreach (['en' => 'ltr', 'ar' => 'rtl', 'ckb' => 'rtl'] as $locale => $direction) {
            foreach (['staff', 'roles', 'branches', 'resources', 'resources?tab=types', 'resources?tab=requirements', 'resources?tab=blocks'] as $page) {
                $separator = str_contains($page, '?') ? '&' : '?';
                $html = $this->get("http://{$slug}.localhost:8000/manager/{$page}{$separator}locale={$locale}")
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
                    ->getContent();

                $text = strip_tags((string) $html);

                expect(preg_match('/\b(manager_staff|permissions|ui)\.[a-z_]+\.[a-z_0-9.]+/', $text))->toBe(0, "raw key on {$page} ({$locale})")
                    ->and(str_contains((string) $html, '>CKB<'))->toBeFalse();
            }
        }
    });
});

it('keeps a branch\'s holidays, coordinates and order through a web edit', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $branch = $this->seedBranch('Karrada');
        $branch->forceFill(['latitude' => '33.3152000', 'longitude' => '44.3661000', 'sort_order' => 7, 'phone' => '+9647701230311'])->save();
        $this->openEveryDay($branch);
        $past = CarbonImmutable::now()->subDays(40)->toDateString();
        $future = CarbonImmutable::now()->addDays(20)->toDateString();
        $this->closeOn($branch, $past, 'Old holiday');
        $this->closeOn($branch, $future, 'Eid');

        Livewire::actingAs($owner)
            ->test(Branches::class)
            ->call('edit', $branch->uuid)
            ->assertSet('phoneCountry', 'IQ')
            ->assertSet('phone', '7701230311')
            ->assertCount('exceptions', 1)
            ->set('name.en', 'Karrada Center')
            ->call('addException')
            ->set('exceptions.1.date', CarbonImmutable::now()->addDays(30)->toDateString())
            ->set('exceptions.1.is_closed', false)
            ->set('exceptions.1.opens_at', '12:00')
            ->set('exceptions.1.closes_at', '18:00')
            ->call('save')
            ->assertHasNoErrors();

        $branch->refresh();

        // Before the fix every web edit wiped these: SaveBranchSchedule
        // replaced the exceptions with [] and SaveBranch reset the rest.
        expect($branch->name->get('en'))->toBe('Karrada Center')
            ->and($branch->latitude)->toBe('33.3152000')
            ->and($branch->longitude)->toBe('44.3661000')
            ->and($branch->sort_order)->toBe(7)
            ->and($branch->phone)->toBe('+9647701230311')
            ->and($branch->hourExceptions()->pluck('note')->filter()->values()->all())->toEqualCanonicalizing(['Old holiday', 'Eid'])
            ->and($branch->hourExceptions()->count())->toBe(3)
            ->and($branch->workingHours()->count())->toBe(7);

        // A date entered twice is refused where it was entered.
        Livewire::actingAs($owner)
            ->test(Branches::class)
            ->call('edit', $branch->uuid)
            ->call('addException')
            ->set('exceptions.2.date', $future)
            ->call('save')
            ->assertHasErrors('exceptions');
    });
});

it('archives and restores a branch, hidden from the public menu on return', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $branch = $this->seedBranch('Mansour');

        Livewire::actingAs($owner)->test(Branches::class)
            ->call('archive', $branch->uuid)
            ->assertSet('noticeTone', 'success')
            ->set('view', 'archived')
            ->assertSee('Mansour')
            ->call('restore', $branch->uuid)
            ->assertSet('noticeTone', 'success');

        $branch->refresh();
        expect($branch->isArchived())->toBeFalse()
            ->and($branch->is_active)->toBeTrue()
            ->and($branch->is_public)->toBeFalse();

        // The main branch refusal is shown as an error, not a success.
        Livewire::actingAs($owner)->test(Branches::class)
            ->call('archive', Branch::main()->uuid)
            ->assertSet('noticeTone', 'danger');
    });
});

it('drives the staff page: scoped list, create without login, profile, services, access and time off', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $main = Branch::main();
        $second = $this->seedBranch('Karrada');
        $this->openEveryDay($main);
        $service = $this->seedService('Haircut', 30, 15000);

        // A scoped manager sees only their branch's people and may pick only it.
        /** @var User $manager */
        $manager = User::query()->create(['name' => 'Branch Manager', 'email' => 'bm@alpha.test', 'phone' => '+9647701230321', 'password' => 'secret-password-1', 'is_active' => true, 'all_branches' => false]);
        $manager->roles()->sync([Role::query()->where('key', SystemRole::Manager->value)->firstOrFail()->id]);
        $manager->syncBranchScope([$main->id]);

        app(CreateEmployee::class)(new NewEmployee(name: ['en' => 'Elsewhere'], branchIds: [$second->id]), $owner);

        Livewire::actingAs($manager)->test(Staff::class)
            ->assertViewHas('employees', fn ($employees): bool => $employees->total() === 0)
            ->call('openForm')
            ->assertSet('branchIds', [$main->id])
            ->assertViewHas('formBranches', fn (array $branches): bool => array_column($branches, 'id') === [$main->id])
            ->set('name.en', 'Layla')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('activationToken', null)
            ->assertViewHas('employees', fn ($employees): bool => $employees->total() === 1);

        $layla = Employee::query()->where('name->en', 'Layla')->firstOrFail();
        expect($layla->user_id)->toBeNull()
            ->and($layla->branchIds())->toBe([$main->id]);

        // The profile edits, assigns services, and gives a login.
        Livewire::actingAs($manager)->test(Profile::class, ['uuid' => $layla->uuid])
            ->call('startEdit')
            ->set('name.ar', 'ليلى')
            ->call('save')
            ->assertHasNoErrors();

        expect($layla->refresh()->name->get('ar'))->toBe('ليلى');

        Livewire::actingAs($manager)->test(Services::class, ['uuid' => $layla->uuid])
            ->set('selected', [$service->uuid])
            ->call('save')
            ->assertHasNoErrors();

        expect(DB::connection('tenant')->table('employee_service')->where('employee_id', $layla->id)->pluck('service_id')->all())->toBe([$service->id]);

        Livewire::actingAs($manager)->test(Access::class, ['uuid' => $layla->uuid])
            ->set('phone', '0770 123 0322')
            ->call('grantLogin')
            ->assertHasNoErrors()
            ->assertSet('activationToken', fn (?string $token): bool => is_string($token) && $token !== '');

        expect($layla->refresh()->user_id)->not->toBeNull();

        // A typo found before the link is handed over is corrected in place.
        Livewire::actingAs($manager)->test(Access::class, ['uuid' => $layla->uuid])
            ->assertViewHas('person', fn (?array $person): bool => $person !== null && $person['can']['contact'] === true)
            ->set('contactPhone', '0770 123 0323')
            ->set('contactEmail', 'Layla@Alpha.test')
            ->call('saveContact')
            ->assertHasNoErrors();

        expect($layla->refresh()->user?->phone)->toBe('+9647701230323')
            ->and($layla->user?->email)->toBe('layla@alpha.test');

        Livewire::actingAs($manager)->test(AvailabilityBlocks::class, ['employee' => $layla->uuid])
            ->call('create')
            ->assertSet('formBranch', $main->uuid)
            ->set('startsAt', CarbonImmutable::now()->addDays(3)->format('Y-m-d').'T13:00')
            ->set('endsAt', CarbonImmutable::now()->addDays(3)->format('Y-m-d').'T14:00')
            ->call('save')
            ->assertHasNoErrors();

        $block = EmployeeAvailabilityBlock::query()->where('employee_id', $layla->id)->firstOrFail();
        // Entered as Baghdad wall-clock time, stored in UTC (+03:00).
        expect($block->starts_at->utc()->format('H:i'))->toBe('10:00');

        // Someone outside the manager's branches is simply not found.
        $elsewhere = Employee::query()->where('name->en', 'Elsewhere')->firstOrFail();
        Livewire::actingAs($manager)->test(Profile::class, ['uuid' => $elsewhere->uuid])
            ->assertViewHas('person', null);

        // Lifecycle: the manager deactivates Layla through the confirmation.
        Livewire::actingAs($manager)->test(Profile::class, ['uuid' => $layla->uuid])
            ->call('askStatus', 'deactivate')
            ->call('setStatus')
            ->assertHasNoErrors();

        expect($layla->refresh()->status)->toBe(EmployeeStatus::Inactive);
    });
});

it('shows the roles page to role viewers, with member counts and a read-only Owner role', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $ownerRole = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();

        Livewire::actingAs($owner)->test(Roles::class)
            ->assertViewHas('roles', fn (array $roles): bool => collect($roles)->firstWhere('owner', true)['members'] === 1)
            ->call('openRole', $ownerRole->uuid)
            ->assertSet('panel', 'view')
            ->call('closePanel')
            ->call('openCreate')
            ->set('name.en', 'Front desk')
            ->set('selected', ['appointment.view', 'customer.view'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('panel', null);

        expect(Role::query()->where('name->en', 'Front desk')->firstOrFail()->permissionCodes())->toEqualCanonicalizing(['appointment.view', 'customer.view']);

        /** @var User $plain */
        $plain = User::query()->create(['name' => 'Plain', 'email' => 'plain@alpha.test', 'is_active' => true]);

        Livewire::actingAs($plain)->test(Roles::class)
            ->assertOk()
            ->assertViewHas('canView', false)
            ->assertViewHas('roles', []);
    });
});

it('keeps a resource\'s active flag and order through an edit', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $type = $this->seedResourceType('Treatment Room');
        $resource = $this->seedResource($type, Branch::main(), 'Room 1', 2, null, 5);
        $resource->forceFill(['is_active' => false])->save();

        Livewire::actingAs($owner)->test(ResourceList::class)
            ->call('edit', $resource->uuid)
            ->assertSet('isActive', false)
            ->assertSet('sortOrder', '5')
            ->set('name.en', 'Room One')
            ->call('save')
            ->assertHasNoErrors();

        $resource->refresh();
        // Before the fix an edit silently reactivated it and reset its order.
        expect($resource->is_active)->toBeFalse()
            ->and($resource->sort_order)->toBe(5)
            ->and($resource->name->get('en'))->toBe('Room One');
    });
});

afterEach(function (): void {
    URL::defaults([]);
    $this->tearDownRegisteredCenters();
});
