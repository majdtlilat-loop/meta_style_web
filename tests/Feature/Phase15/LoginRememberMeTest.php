<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Livewire\Sadmin\Auth\Login as PlatformLogin;
use App\Livewire\Sadmin\Auth\MfaChallenge;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Sign-in: password visibility and "Remember me"
|--------------------------------------------------------------------------
|
| Remember-me is Laravel's own recaller cookie, per guard. It stands in for the
| PASSWORD step only: a remembered Super Admin is still asked for a second
| factor in every new session, and a center's cookie can never sign anybody in
| anywhere else.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
});

function loginRememberPlatformUser(string $recoveryCode): PlatformUser
{
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill([
        'mfa_confirmed_at' => now(),
        'mfa_recovery_codes' => [hash('sha256', $recoveryCode)],
        'remember_token' => null,
    ])->save();

    return $user;
}

/**
 * The real Livewire request the center login page sends, with remember-me.
 */
function submitCenterLoginWithRemember(TestCase $test, string $slug, string $email, string $password, bool $remember): TestResponse
{
    $page = $test->get("http://{$slug}.localhost:8000/login");
    $page->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    expect($matches)->toHaveKey(1);

    return $test
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson("http://{$slug}.localhost:8000/livewire/update", [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'updates' => ['identifier' => $email, 'password' => $password, 'remember' => $remember],
                'calls' => [['path' => '', 'method' => 'submit', 'params' => []]],
            ]],
        ])
        ->assertOk();
}

it('renders a show / hide password control and a remember-me box on the Super Admin sign-in', function (): void {
    $this->get('http://superadmin.localhost:8000/login?locale=en')
        ->assertOk()
        // A type="button" control can never submit the form it sits in.
        ->assertSee('<button type="button" class="password-field__toggle" data-password-toggle aria-controls="password" aria-pressed="false"', false)
        ->assertSee('aria-label="Show password"', false)
        ->assertSee('data-label-hide="Hide password"', false)
        ->assertSee('wire:model="remember"', false)
        ->assertSee('Remember me');

    $this->get('http://superadmin.localhost:8000/login?locale=ar')
        ->assertOk()
        ->assertSee('تذكرني')
        ->assertSee('aria-label="إظهار كلمة المرور"', false);

    $this->get('http://superadmin.localhost:8000/login?locale=ckb')
        ->assertOk()
        ->assertSee('لەبیرم بێت')
        ->assertSee('aria-label="پیشاندانی وشەی نهێنی"', false);
});

it('remembers a Super Admin only when asked, and only after MFA', function (): void {
    $user = loginRememberPlatformUser('remember-recovery-1');
    $recaller = Auth::guard('platform')->getRecallerName();

    Livewire::test(PlatformLogin::class)
        ->set('email', 'admin@meta-style.local')
        ->set('password', 'MetaStyle@123456')
        ->set('remember', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('superadmin.mfa.challenge'));

    // Password accepted, nobody signed in yet: MFA still stands in the way.
    expect(Auth::guard('platform')->check())->toBeFalse()
        ->and(Cookie::hasQueued($recaller))->toBeFalse();

    Livewire::test(MfaChallenge::class)
        ->set('useRecoveryCode', true)
        ->set('code', 'remember-recovery-1')
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user, 'platform');
    expect(Cookie::hasQueued($recaller))->toBeTrue()
        ->and($user->fresh()?->getRememberToken())->not->toBeNull()
        // The center guard's recaller is a different cookie, never set here.
        ->and(Cookie::hasQueued(Auth::guard('web')->getRecallerName()))->toBeFalse();
});

it('keeps an unchecked Super Admin sign-in to the session alone', function (): void {
    $user = loginRememberPlatformUser('remember-recovery-2');

    Livewire::test(PlatformLogin::class)
        ->set('email', 'admin@meta-style.local')
        ->set('password', 'MetaStyle@123456')
        ->call('submit')
        ->assertHasNoErrors();

    Livewire::test(MfaChallenge::class)
        ->set('useRecoveryCode', true)
        ->set('code', 'remember-recovery-2')
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user, 'platform');
    expect(Cookie::hasQueued(Auth::guard('platform')->getRecallerName()))->toBeFalse()
        // No remember token is minted for a session-only sign-in.
        ->and($user->fresh()?->getRememberToken())->toBeEmpty();
});

it('asks a remembered Super Admin for MFA again instead of looping between redirects', function (): void {
    $user = loginRememberPlatformUser('remember-recovery-3');

    // A fresh session the recaller has signed back in: authenticated, never
    // through MFA in this session.
    $this->actingAs($user, 'platform');

    $this->get('http://superadmin.localhost:8000/')
        ->assertRedirect(route('superadmin.mfa.challenge'));

    // The challenge is reachable — before the fix `guest:platform` bounced it
    // straight back to the dashboard, which bounced it back here.
    $this->get('http://superadmin.localhost:8000/mfa/challenge')->assertOk();

    // Remember-me never skips the second factor.
    Livewire::test(MfaChallenge::class)
        ->set('useRecoveryCode', true)
        ->set('code', 'not-the-code')
        ->call('submit')
        ->assertHasErrors('code');

    expect(session(EnsurePlatformMfa::SESSION_KEY))->toBeNull();

    Livewire::test(MfaChallenge::class)
        ->set('useRecoveryCode', true)
        ->set('code', 'remember-recovery-3')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('superadmin.dashboard'));

    expect(session(EnsurePlatformMfa::SESSION_KEY))->toBeNumeric();
    $this->get('http://superadmin.localhost:8000/')->assertOk();
});

it('sends a verified Super Admin, and a stranger, away from the MFA screens', function (): void {
    $user = loginRememberPlatformUser('remember-recovery-4');

    $this->get('http://superadmin.localhost:8000/mfa/challenge')
        ->assertRedirect(route('superadmin.login'));

    $this->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/mfa/challenge')
        ->assertRedirect(route('superadmin.dashboard'));
});

it('remembers a center user only when asked, on that center only', function (): void {
    $password = 'correct-horse-battery-staple';
    $center = $this->registerCenter('Remember Center', 'owner@remember-center.test', $password);
    $slug = $center['registration']->requested_slug;
    $webRecaller = Auth::guard('web')->getRecallerName();
    $platformRecaller = Auth::guard('platform')->getRecallerName();

    expect($webRecaller)->not->toBe($platformRecaller);

    $this->get("http://{$slug}.localhost:8000/login")
        ->assertOk()
        ->assertSee('data-password-toggle', false)
        ->assertSee('<button type="button" class="password-field__toggle"', false)
        ->assertSee('wire:model="remember"', false)
        ->assertSee('Remember me');

    $unchecked = submitCenterLoginWithRemember($this, $slug, 'owner@remember-center.test', $password, false);
    expect($unchecked->json('components.0.effects.redirect'))->toBe("http://{$slug}.localhost:8000/manager");
    $unchecked->assertCookieMissing($webRecaller);

    // A new, signed-out browser session. (Logging out through the guard here
    // would touch the tenant `users` table with no center bound — the
    // isolation guard rightly refuses that outside a request.)
    app('auth')->forgetGuards();
    $this->flushSession();

    $remembered = submitCenterLoginWithRemember($this, $slug, 'owner@remember-center.test', $password, true);
    expect($remembered->json('components.0.effects.redirect'))->toBe("http://{$slug}.localhost:8000/manager");
    $remembered->assertCookie($webRecaller)
        ->assertCookieMissing($platformRecaller);

    // The center's cookie signs nobody in to the platform: its guard reads a
    // different cookie, on a different host.
    $cookie = $remembered->getCookie($webRecaller);
    app('auth')->forgetGuards();
    $this->flushSession();

    $this->withCookie($webRecaller, (string) $cookie?->getValue())
        ->get('http://superadmin.localhost:8000/')
        ->assertRedirect('http://superadmin.localhost:8000/login');
    expect(Auth::guard('platform')->check())->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
