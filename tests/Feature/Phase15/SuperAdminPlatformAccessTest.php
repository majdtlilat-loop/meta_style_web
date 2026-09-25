<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Platform\Credentials\AiProviderSettings;
use App\Kernel\Platform\Credentials\PlatformProviderCredential;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Identity\Actions\ManagePlatformRoles;
use App\Kernel\Platform\Identity\Actions\ManagePlatformUsers;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Mail\PlatformAccessEmail;
use App\Kernel\Platform\Identity\Models\PlatformRole;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Identity\PlatformLanding;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\SaaS\Models\Plan;
use App\Livewire\Sadmin\Auth\Login;
use App\Livewire\Sadmin\Settings\Ai as AiSettings;
use App\Livewire\Sadmin\Settings\Index as SettingsIndex;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Platform access: people, roles, sign-in policy and secrets
|--------------------------------------------------------------------------
|
| The Super Admin manages its own staff and security from the screens. None
| of it may weaken the platform by accident: nobody is invited with a known
| password, nobody grants what they do not hold, the platform always keeps
| someone who can manage users, switching MFA off needs the actor's own
| password and never deletes an enrolled secret, and the AI key is stored
| encrypted and never shown again.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->admin->forceFill(['password' => 'Known-admin-pass-2026', 'mfa_confirmed_at' => now()])->save();
    $this->actingAs($this->admin, 'platform');
});

afterEach(function (): void {
    // Roles, plans and the currency catalog are reference data: they are not
    // truncated between tests, so what a test adds it removes.
    $roles = DB::connection('control')->table('platform_roles')->where('is_system', false)->pluck('id');
    DB::connection('control')->table('platform_role_permissions')->whereIn('role_id', $roles)->delete();
    DB::connection('control')->table('platform_roles')->whereIn('id', $roles)->delete();
    Schema::connection('control')->disableForeignKeyConstraints();
    DB::connection('control')->table('plans')->where('code', 'access_qar_plan')->delete();
    Schema::connection('control')->enableForeignKeyConstraints();
    DB::connection('control')->table('platform_currencies')->whereIn('code', ['EUR', 'QAR'])->delete();
    DB::connection('control')->table('platform_currencies')->update(['is_default' => false]);
    DB::connection('control')->table('platform_currencies')->where('code', 'IQD')->update(['is_default' => true, 'is_enabled' => true]);
});

function supportOnlyRole(PlatformUser $actor): PlatformRole
{
    return app(ManagePlatformRoles::class)->create(
        ['en' => 'Ticket desk', 'ar' => 'مكتب التذاكر', 'ckb' => 'مێزی تیکێت'],
        ['en' => '', 'ar' => '', 'ckb' => ''],
        ManagePlatformRoles::TEMPLATES['support_tickets_only'],
        $actor,
    );
}

it('invites platform staff with a one-time link and never a password', function (): void {
    Mail::fake();
    $role = supportOnlyRole($this->admin);

    $user = app(ManagePlatformUsers::class)->invite('Rana Support', 'Rana@Support.test', [$role->id], $this->admin);

    expect($user->email)->toBe('rana@support.test')
        ->and($user->is_active)->toBeTrue()
        ->and($user->password_changed_at)->toBeNull();

    Mail::assertQueued(PlatformAccessEmail::class, function (PlatformAccessEmail $mail) use ($user): bool {
        return $mail->hasTo($user->email)
            && $mail->kind === 'invite'
            && str_contains($mail->url, '/password/reset/')
            // No personal data in the link.
            && ! str_contains($mail->url, 'rana');
    });

    expect(PlatformAuditLog::query()->where('action', 'platform.user.invited')->where('target_id', $user->uuid)->exists())->toBeTrue();

    // A ticket-desk role opens on the support workspace, not on a page it cannot see.
    expect($user->hasPermission('platform.support.view'))->toBeTrue()
        ->and($user->hasPermission('platform.dashboard.view'))->toBeFalse()
        ->and(PlatformLanding::routeFor($user))->toBe('superadmin.support.index');
});

it('refuses privilege escalation and keeps someone who can manage users', function (): void {
    Mail::fake();
    $desk = supportOnlyRole($this->admin);
    $agent = app(ManagePlatformUsers::class)->invite('Desk Agent', 'desk@support.test', [$desk->id], $this->admin);
    $superAdmin = PlatformRole::query()->where('key', 'super_admin')->firstOrFail();

    // The agent cannot hand out a role carrying permissions they do not hold.
    expect(fn () => app(ManagePlatformUsers::class)->invite('Someone', 'someone@support.test', [$superAdmin->id], $agent))
        ->toThrow(DomainException::class);
    expect(fn () => app(ManagePlatformRoles::class)->create(['en' => 'Wider', 'ar' => '', 'ckb' => ''], ['en' => '', 'ar' => '', 'ckb' => ''], ['platform.user.manage'], $agent))
        ->toThrow(DomainException::class);

    // Nobody blocks themselves, and the last user manager cannot be removed.
    expect(fn () => app(ManagePlatformUsers::class)->setActive($this->admin, false, $this->admin, 'Testing the guard'))
        ->toThrow(DomainException::class);
    expect(fn () => app(ManagePlatformUsers::class)->setActive($this->admin, false, $agent, 'Testing the guard'))
        ->toThrow(DomainException::class, __('sadmin_users.errors.last_manager'));
    expect($this->admin->refresh()->is_active)->toBeTrue();

    // The system Super Admin role is never edited or archived from the screens.
    expect(fn () => app(ManagePlatformRoles::class)->archive($superAdmin, $this->admin, 'Not allowed'))
        ->toThrow(DomainException::class);
});

it('archives platform staff without deleting them and removes their access', function (): void {
    Mail::fake();
    $desk = supportOnlyRole($this->admin);
    $agent = app(ManagePlatformUsers::class)->invite('Leaving Agent', 'leaving@support.test', [$desk->id], $this->admin);

    app(ManagePlatformUsers::class)->archive($agent, $this->admin, 'Left the company');
    $agent->refresh();

    expect($agent->archived_at)->not->toBeNull()
        ->and($agent->is_active)->toBeFalse()
        ->and($agent->hasPermission('platform.support.view'))->toBeFalse()
        ->and(PlatformUser::query()->whereKey($agent->id)->exists())->toBeTrue();

    // A role in use cannot be deleted outright; archiving takes it from its
    // holders first, and only then may it go.
    expect(fn () => app(ManagePlatformRoles::class)->delete($desk, $this->admin))->toThrow(DomainException::class);
    app(ManagePlatformRoles::class)->archive($desk, $this->admin, 'Desk merged');
    expect($desk->refresh()->users()->exists())->toBeFalse();
    app(ManagePlatformRoles::class)->delete($desk, $this->admin);
    expect(PlatformRole::query()->whereKey($desk->id)->exists())->toBeFalse()
        ->and(PlatformAuditLog::query()->where('action', 'platform.role.deleted')->exists())->toBeTrue();
});

it('switches MFA enforcement off only with the actor password and keeps enrolled secrets', function (): void {
    $secret = (string) $this->admin->mfa_secret;

    Livewire::test(SettingsIndex::class)
        ->call('openPanel', 'mfa-off')
        ->set('reason', 'Temporary: authenticator app outage')
        ->set('currentPassword', 'not-my-password')
        ->call('setMfa')
        ->assertHasErrors('currentPassword');

    expect(app(PlatformPreferences::class)->mfaRequired())->toBeTrue();

    Livewire::test(SettingsIndex::class)
        ->call('openPanel', 'mfa-off')
        ->set('reason', 'Temporary: authenticator app outage')
        ->set('currentPassword', 'Known-admin-pass-2026')
        ->call('setMfa')
        ->assertHasNoErrors();

    $preferences = app(PlatformPreferences::class);
    expect($preferences->mfaRequired())->toBeFalse()
        ->and($this->admin->refresh()->mfa_secret)->toBe($secret)
        ->and($this->admin->mfa_confirmed_at)->not->toBeNull();

    $entry = PlatformAuditLog::query()->where('action', 'platform.security.mfa_enforcement.disabled')->firstOrFail();
    expect($entry->severity)->toBe('critical')
        ->and($entry->reason)->toBe('Temporary: authenticator app outage');

    // With enforcement off the password is the whole sign-in; back on, MFA is required again.
    auth('platform')->logout();
    Livewire::test(Login::class)
        ->set('email', 'admin@meta-style.local')
        ->set('password', 'Known-admin-pass-2026')
        ->call('submit')
        ->assertRedirect(route(PlatformLanding::routeFor($this->admin)));
    expect(auth('platform')->check())->toBeTrue();

    $preferences->setMfaRequired(true, Actor::platform($this->admin), 'Outage over');
    auth('platform')->logout();
    Livewire::test(Login::class)
        ->set('email', 'admin@meta-style.local')
        ->set('password', 'Known-admin-pass-2026')
        ->call('submit')
        ->assertRedirect(route('superadmin.mfa.challenge'));
    expect(auth('platform')->check())->toBeFalse();
});

it('refuses the security settings to staff without the security permission', function (): void {
    Mail::fake();
    $desk = supportOnlyRole($this->admin);
    $agent = app(ManagePlatformUsers::class)->invite('Desk Agent', 'desk2@support.test', [$desk->id], $this->admin);
    // Enrolled, so requests reach the permission check rather than MFA set-up.
    $agent->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($agent, 'platform');

    $this->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/settings')
        ->assertForbidden();
    Livewire::test(SettingsIndex::class)->assertForbidden();

    // Someone who may manage settings but not security still cannot touch
    // sign-in policy or the AI key: not by opening the confirmation, and not
    // by a crafted payload posted straight at the action.
    $settingsOnly = app(ManagePlatformRoles::class)->create(['en' => 'Settings desk', 'ar' => '', 'ckb' => ''], ['en' => '', 'ar' => '', 'ckb' => ''], ['platform.settings.manage'], $this->admin);
    $operator = app(ManagePlatformUsers::class)->invite('Settings Operator', 'operator@support.test', [$settingsOnly->id], $this->admin);
    $operator->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($operator, 'platform');

    Livewire::test(SettingsIndex::class)->assertOk()->call('openPanel', 'mfa-off')->assertForbidden();
    Livewire::test(SettingsIndex::class)
        ->set('panel', 'mfa-off')
        ->set('reason', 'Trying to weaken sign-in')
        ->set('currentPassword', 'anything')
        ->call('setMfa')
        ->assertForbidden();
    Livewire::test(AiSettings::class)->assertForbidden();

    // The AI page alone does not reach the key: that is a security permission.
    $aiOnly = app(ManagePlatformRoles::class)->create(['en' => 'AI desk', 'ar' => '', 'ckb' => ''], ['en' => '', 'ar' => '', 'ckb' => ''], ['platform.ai.manage'], $this->admin);
    $aiOperator = app(ManagePlatformUsers::class)->invite('AI Operator', 'ai-operator@support.test', [$aiOnly->id], $this->admin);
    $aiOperator->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($aiOperator, 'platform');

    Livewire::test(AiSettings::class)->assertOk()->call('openPanel', 'key')->assertForbidden();
    Livewire::test(AiSettings::class)
        ->set('apiKey', 'sk-proj_NotAllowed_0123456789abcdef')
        ->call('saveApiKey')
        ->assertForbidden();

    expect(app(PlatformPreferences::class)->mfaRequired())->toBeTrue()
        ->and(PlatformProviderCredential::query()->exists())->toBeFalse();
});

it('stores the AI key encrypted, shows only a hint and never renders or audits the key', function (): void {
    $key = 'sk-proj_TestOnlyKey_0123456789abcdefWXYZ';

    Livewire::test(AiSettings::class)
        ->call('openPanel', 'key')
        ->set('apiKey', $key)
        ->call('saveApiKey')
        ->assertHasNoErrors()
        ->assertSet('apiKey', '')
        ->assertDontSee($key)
        ->assertSee('WXYZ');

    $raw = (string) DB::connection('control')->table('platform_provider_credentials')->where('provider', 'openai')->value('credentials');
    expect($raw)->not->toBe('')
        ->and(str_contains($raw, $key))->toBeFalse('the key is stored in plain text');

    /** @var PlatformProviderCredential $row */
    $row = PlatformProviderCredential::query()->where('provider', 'openai')->firstOrFail();
    expect($row->hint)->toBe('WXYZ')
        ->and(app(AiProviderSettings::class)->status()['source'])->toBe('platform');

    $audit = PlatformAuditLog::query()->where('action', 'platform.ai.credential.replaced')->firstOrFail();
    expect(str_contains((string) json_encode($audit->toArray()), 'TestOnlyKey'))->toBeFalse('the key reached the audit log');

    $this->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/settings/ai')
        ->assertOk()
        ->assertSee('WXYZ')
        ->assertDontSee($key);
});

it('guards the currency catalog: default stays enabled and decimals lock once in use', function (): void {
    $currencies = app(PlatformCurrencies::class);
    $actor = Actor::platform($this->admin);

    $currencies->save('EUR', ['en' => 'Euro', 'ar' => 'يورو', 'ckb' => 'یۆرۆ'], '€', 2, true, 5, $actor);
    expect($currencies->enabledCodes())->toContain('EUR')
        ->and(PlatformAuditLog::query()->where('action', 'platform.currency.added')->where('target_id', 'EUR')->exists())->toBeTrue();

    $default = $currencies->defaultCode();
    $defaultRow = $currencies->find($default);
    expect(fn () => $currencies->save($default, $defaultRow->name->all(), $defaultRow->symbol, $defaultRow->decimals, false, 1, $actor))
        ->toThrow(DomainException::class);

    // A currency with a fixed ISO exponent never changes its decimal places.
    expect(fn () => $currencies->save('IQD', ['en' => 'Iraqi dinar', 'ar' => 'دينار عراقي', 'ckb' => 'دیناری عێراقی'], 'IQD', 2, true, 1, $actor))
        ->toThrow(DomainException::class);

    // A catalog currency's decimal places lock once something is priced in it.
    $currencies->save('QAR', ['en' => 'Qatari riyal', 'ar' => 'ريال قطري', 'ckb' => 'ڕیاڵی قەتەری'], 'QAR', 2, true, 6, $actor);
    Plan::query()->create([
        'code' => 'access_qar_plan', 'name' => ['en' => 'Doha', 'ar' => 'الدوحة', 'ckb' => 'دۆحە'], 'price_minor' => 1000,
        'billing_period' => 'monthly', 'currency' => 'QAR', 'monthly_price_minor' => 1000, 'is_public' => false, 'is_active' => true,
    ]);
    expect(fn () => $currencies->save('QAR', ['en' => 'Qatari riyal', 'ar' => 'ريال قطري', 'ckb' => 'ڕیاڵی قەتەری'], 'QAR', 3, true, 6, $actor))
        ->toThrow(DomainException::class, __('sadmin_currencies.errors.decimals_locked'));

    $currencies->setDefault('EUR', $actor);
    expect($currencies->defaultCode())->toBe('EUR');
});
