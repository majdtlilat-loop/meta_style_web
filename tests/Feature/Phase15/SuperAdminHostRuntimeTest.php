<?php

declare(strict_types=1);

use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Livewire\Sadmin\Auth\Login;
use App\Livewire\Sadmin\Auth\MfaChallenge;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->platformUser = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
});

it('keeps guest and authenticated Super Admin redirects on the platform host', function (): void {
    $this->get('http://superadmin.localhost:8000/')
        ->assertRedirect('http://superadmin.localhost:8000/login');

    $this->platformUser->forceFill(['mfa_confirmed_at' => now()])->save();

    $this->actingAs($this->platformUser, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/login')
        ->assertRedirect('http://superadmin.localhost:8000');

    $this->get('http://superadmin.localhost:8000/')
        ->assertOk()
        ->assertDontSee('http://localhost:8000"', false);

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('completes platform MFA on the Super Admin dashboard', function (): void {
    $recoveryCode = 'phase15-host-recovery';
    $this->platformUser->forceFill([
        'mfa_confirmed_at' => now(),
        'mfa_recovery_codes' => [hash('sha256', $recoveryCode)],
    ])->save();

    session()->put(Login::PENDING_USER_KEY, $this->platformUser->getKey());

    Livewire::test(MfaChallenge::class)
        ->set('useRecoveryCode', true)
        ->set('code', $recoveryCode)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('http://superadmin.localhost:8000');

    $this->assertAuthenticatedAs($this->platformUser, 'platform');
    expect(session(EnsurePlatformMfa::SESSION_KEY))->toBeNumeric()
        ->and(app(TenantContext::class)->isBound())->toBeFalse();
});

it('keeps navigation and locale changes on the Super Admin host', function (): void {
    $this->platformUser->forceFill(['mfa_confirmed_at' => now()])->save();

    $response = $this->actingAs($this->platformUser, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/?locale=ar');

    $response->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false)
        ->assertSee('href="http://superadmin.localhost:8000/centers"', false)
        ->assertSee('href="http://superadmin.localhost:8000/plans"', false)
        ->assertSee('href="http://superadmin.localhost:8000/billing"', false)
        ->assertSee('href="http://superadmin.localhost:8000/support"', false)
        ->assertSee('href="http://superadmin.localhost:8000/cms"', false)
        ->assertSee('href="http://superadmin.localhost:8000/operations"', false)
        ->assertSee('href="http://superadmin.localhost:8000/settings"', false)
        ->assertSee('href="http://superadmin.localhost:8000/?locale=ckb"', false);

    expect(session(SetLocale::SESSION_KEY))->toBe('ar')
        ->and(app(TenantContext::class)->isBound())->toBeFalse();

    $this->get('http://superadmin.localhost:8000/plans?locale=ckb')
        ->assertOk()
        ->assertSee('<html lang="ckb" dir="rtl"', false)
        ->assertSee('KU')
        ->assertDontSee('>CKB<', false);

    $this->get('http://superadmin.localhost:8000/plans?locale=en')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false);
});

it('returns platform logout to platform login', function (): void {
    $this->platformUser->forceFill(['mfa_confirmed_at' => now()])->save();

    $this->actingAs($this->platformUser, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->post('http://superadmin.localhost:8000/logout')
        ->assertRedirect('http://superadmin.localhost:8000/login');

    $this->assertGuest('platform');
    expect(app(TenantContext::class)->isBound())->toBeFalse();
});
