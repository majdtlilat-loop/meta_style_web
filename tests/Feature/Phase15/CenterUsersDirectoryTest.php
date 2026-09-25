<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Directory\CenterUserDirectory;
use App\Kernel\Platform\Directory\CenterUserEntry;
use App\Kernel\Platform\Directory\CenterUserSearch;
use App\Kernel\Platform\Identity\Actions\ManagePlatformRoles;
use App\Kernel\Platform\Identity\Actions\ManagePlatformUsers;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\CenterUsers\Index as CenterUsers;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Center users: every center's owners, managers and staff, for the platform
|--------------------------------------------------------------------------
|
| A control-plane read model, so listing and searching never opens every
| tenant database. Phone search works on the normalised number. Viewing and
| managing are separate permissions; every change goes to the center first,
| is audited without contact values, and re-projects that center.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->admin->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp]);

    $this->alpha = $this->registerCenter('Alpha Salon', 'owner@alpha-salon.test');
    $this->beta = $this->registerCenter('Beta Spa', 'owner@beta-spa.test');

    // A legacy account in Beta, created before phones were required.
    $this->asCenter($this->beta['tenant'], function (): void {
        User::query()->create(['name' => 'Legacy Stylist', 'email' => 'legacy@beta-spa.test', 'phone' => null, 'is_active' => true, 'is_owner' => false]);
    });
    app(CenterUserDirectory::class)->refreshAll();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
    $roles = DB::connection('control')->table('platform_roles')->where('is_system', false)->pluck('id');
    DB::connection('control')->table('platform_role_permissions')->whereIn('role_id', $roles)->delete();
    DB::connection('control')->table('platform_roles')->whereIn('id', $roles)->delete();
});

it('lists every center user across centers from the directory, with phone missing shown, not invented', function (): void {
    expect(CenterUserEntry::query()->count())->toBe(3)
        ->and(CenterUserEntry::query()->where('kind', 'owner')->count())->toBe(2);

    $legacy = CenterUserEntry::query()->where('email', 'legacy@beta-spa.test')->firstOrFail();
    expect($legacy->phone_e164)->toBeNull()
        ->and($legacy->tenant_id)->toBe($this->beta['tenant']->id);

    $missing = app(CenterUserSearch::class)->query(['phone' => 'missing'])->pluck('email')->all();
    expect($missing)->toBe(['legacy@beta-spa.test']);

    $this->get(rtrim(app(PlatformHosts::class)->superAdminUrl(), '/').'/center-users')
        ->assertOk()
        ->assertSee('Alpha Salon')
        ->assertSee('Beta Spa')
        ->assertSee(__('phone_field.missing'));
});

it('finds the same person by any spelling of their phone number', function (): void {
    $search = app(CenterUserSearch::class);
    foreach (['7701234567', '07701234567', '+9647701234567', '9647701234567', '+964 770 123 4567', '00964 770 123 4567'] as $typed) {
        expect($search->query(['search' => $typed])->count())->toBe(2, $typed);
    }
    expect($search->query(['search' => '7709999999'])->count())->toBe(0)
        ->and($search->query(['search' => 'alpha-salon'])->pluck('email')->all())->toBe(['owner@alpha-salon.test'])
        ->and($search->query(['search' => 'Legacy'])->count())->toBe(1)
        ->and($search->query(['center' => (string) $this->alpha['tenant']->id])->count())->toBe(1)
        ->and($search->query(['kind' => 'owner', 'center' => (string) $this->beta['tenant']->id])->count())->toBe(1)
        ->and($search->query(['role' => 'owner'])->count())->toBe(2)
        ->and($search->query(['status' => 'blocked'])->count())->toBe(0);
});

it('lets a manager complete a missing phone and block staff, audited without contact values', function (): void {
    $legacy = CenterUserEntry::query()->where('email', 'legacy@beta-spa.test')->firstOrFail();

    $page = Livewire::test(CenterUsers::class)
        ->call('show', $legacy->id)
        ->call('openPanel', 'edit')
        ->assertSet('editPhoneCountry', 'IQ')
        ->set('editPhone', '')
        ->call('saveIdentity')
        ->assertHasErrors(['editPhone' => 'required']);

    $page->set('editPhone', '0770 555 1212')->call('saveIdentity')->assertHasNoErrors();

    $this->asCenter($this->beta['tenant'], function (): void {
        expect(User::query()->where('email', 'legacy@beta-spa.test')->firstOrFail()->phone)->toBe('+9647705551212');
    });
    expect(CenterUserEntry::query()->findOrFail($legacy->id)->phone_e164)->toBe('+9647705551212');

    $audit = PlatformAuditLog::query()->where('action', 'platform.center.user.identity_updated')->firstOrFail();
    expect($audit->tenant_id)->toBe($this->beta['tenant']->id)
        ->and(json_encode([$audit->before, $audit->after]))->not->toContain('7705551212');

    // Block and reactivate a staff account; the owner is protected.
    $page->call('openPanel', 'block')->set('reason', 'Left the company')->call('setActive', false)->assertHasNoErrors();
    expect(CenterUserEntry::query()->findOrFail($legacy->id)->is_active)->toBeFalse();
    $page->call('openPanel', 'reactivate')->set('reason', 'Came back')->call('setActive', true)->assertHasNoErrors();
    expect(CenterUserEntry::query()->findOrFail($legacy->id)->is_active)->toBeTrue();

    $owner = CenterUserEntry::query()->where('email', 'owner@beta-spa.test')->firstOrFail();
    Livewire::test(CenterUsers::class)->call('show', $owner->id)->call('openPanel', 'block')->set('reason', 'Should not work')->call('setActive', false)->assertHasErrors('reason');
    expect(CenterUserEntry::query()->findOrFail($owner->id)->is_active)->toBeTrue();
});

it('separates viewing center users from managing them', function (): void {
    $role = app(ManagePlatformRoles::class)->create(['en' => 'Viewer', 'ar' => 'مشاهد', 'ckb' => 'بینەر'], ['en' => '', 'ar' => '', 'ckb' => ''], ['platform.center_user.view'], $this->admin);
    $viewer = app(ManagePlatformUsers::class)->invite('Viewer', 'viewer-cu@example.test', [$role->id], $this->admin);
    $viewer->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($viewer, 'platform');

    $this->get(rtrim(app(PlatformHosts::class)->superAdminUrl(), '/').'/center-users')->assertOk();
    $legacy = CenterUserEntry::query()->where('email', 'legacy@beta-spa.test')->firstOrFail();
    Livewire::test(CenterUsers::class)->call('show', $legacy->id)->call('openPanel', 'edit')->assertForbidden();
});

it('keeps the directory in step with the centers', function (): void {
    $this->asCenter($this->alpha['tenant'], function (): void {
        User::query()->where('is_owner', true)->firstOrFail()->forceFill(['name' => 'Renamed Owner'])->save();
    });
    app(CenterUserDirectory::class)->refreshTenant(TenantModel::query()->findOrFail($this->alpha['tenant']->id));
    expect(CenterUserEntry::query()->where('tenant_id', $this->alpha['tenant']->id)->value('name'))->toBe('Renamed Owner');

    $this->artisan('metastyle:center-users:project')->assertSuccessful();
    expect(CenterUserEntry::query()->count())->toBe(3);
});
