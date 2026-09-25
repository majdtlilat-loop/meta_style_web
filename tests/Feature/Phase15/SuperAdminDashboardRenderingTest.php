<?php

declare(strict_types=1);

use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
});

it('renders the authenticated post-MFA dashboard in every platform locale', function (
    string $locale,
    string $direction,
    string $usageLabel,
    string $dashboardTitle,
): void {
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();

    $response = $this
        ->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get("http://superadmin.localhost:8000/?locale={$locale}");

    $response
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
        ->assertSee($usageLabel)
        // The page heading itself — the sidebar also says "Overview", so a
        // bare text match would pass without the dashboard rendering at all.
        ->assertSee('<h1>'.$dashboardTitle.'</h1>', false)
        ->assertDontSee('superadmin_ui.navigation.usage');
})->with([
    'English' => ['en', 'ltr', 'Usage', 'Overview'],
    'Arabic' => ['ar', 'rtl', 'الاستهلاك', 'نظرة عامة'],
    'Kurdish Sorani' => ['ckb', 'rtl', 'بەکارهێنان', 'پوختە'],
]);

it('still enforces the platform dashboard permission after MFA', function (): void {
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();
    $user->roles()->detach();
    $user->forgetPermissionCache();

    $this
        ->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/')
        ->assertForbidden();
});

it('still requires initial MFA enrollment before rendering the dashboard', function (): void {
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();

    expect($user->hasConfirmedMfa())->toBeFalse();

    $this
        ->actingAs($user, 'platform')
        ->get('http://superadmin.localhost:8000/')
        ->assertRedirect(route('superadmin.mfa.setup'));
});

it('still requires a verified MFA session for an enrolled platform user', function (): void {
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();

    $this
        ->actingAs($user, 'platform')
        ->get('http://superadmin.localhost:8000/')
        ->assertRedirect(route('superadmin.mfa.challenge'));
});

it('persists an explicit locale across Super Admin Livewire destinations', function (): void {
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();

    $this->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('http://superadmin.localhost:8000/?locale=ar')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false);

    expect(session(SetLocale::SESSION_KEY))->toBe('ar');

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('http://superadmin.localhost:8000/centers')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false);

    $this->get('http://superadmin.localhost:8000/centers?locale=ckb')
        ->assertOk()
        ->assertSee('<html lang="ckb" dir="rtl"', false)
        ->assertSee('KU');

    $this->get('http://superadmin.localhost:8000/plans')
        ->assertOk()
        ->assertSee('<html lang="ckb" dir="rtl"', false);

    $this->get('http://superadmin.localhost:8000/plans?locale=en')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false);

    $this->get('http://superadmin.localhost:8000/')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false);
});

it('persists a guest locale choice without bypassing platform authentication', function (): void {
    $this->get('http://superadmin.localhost:8000/?locale=ar')
        ->assertRedirect(route('superadmin.login'));

    expect(session(SetLocale::SESSION_KEY))->toBe('ar');

    $this->get('http://superadmin.localhost:8000/login')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false);
});
