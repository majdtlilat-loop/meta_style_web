<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
|--------------------------------------------------------------------------
| Center sign-in polish and the shell's translation groups
|--------------------------------------------------------------------------
|
| The reset flow flashes a confirmation that the sign-in page now shows; the
| reset form states the password rule before anyone trips over it. The shell's
| own groups stay in exact EN / AR / KU parity with non-empty scalar leaves.
|
*/

it('confirms a password reset on the sign-in page and states the rule on the reset form', function (): void {
    $center = $this->registerCenter('Auth Polish', 'owner@auth-polish.test');
    $slug = $center['registration']->requested_slug;

    $this->withSession(['password_reset' => __('center_auth.reset.updated')])
        ->get("http://{$slug}.localhost:8000/login?locale=en")
        ->assertOk()
        ->assertSee('Password updated. Sign in with your new password.')
        ->assertSee('data-password-toggle', false)
        ->assertSee('wire:model="remember"', false);

    $this->flushSession();

    $this->get("http://{$slug}.localhost:8000/login?locale=en")
        ->assertOk()
        ->assertDontSee('Password updated. Sign in with your new password.');

    $this->get("http://{$slug}.localhost:8000/reset-password/".str_repeat('a', 64).'?locale=en')
        ->assertOk()
        ->assertSee('At least 10 characters, with letters and numbers.')
        ->assertSee('aria-describedby="password-help"', false);
});

it('keeps the shell translation groups in exact parity, scalar and non-empty', function (): void {
    foreach (['manager_shell', 'manager_plan', 'manager_support', 'notifications_inbox', 'center_auth'] as $group) {
        $english = Arr::dot(require lang_path("en/{$group}.php"));

        foreach (['ar', 'ckb'] as $locale) {
            $translated = Arr::dot(require lang_path("{$locale}/{$group}.php"));

            expect(array_keys($translated))->toBe(array_keys($english), "{$locale}/{$group}.php is out of step with English");

            foreach ($translated as $key => $value) {
                expect($value)->toBeString("{$locale}.{$group}.{$key}")->not->toBe('');
            }
        }
    }

    // The navigation keys the shell reads exist in every language.
    foreach (['en', 'ar', 'ckb'] as $locale) {
        $nav = (require lang_path("{$locale}/ui.php"))['manager_nav'];
        foreach (['operations', 'customers', 'center', 'finance', 'analytics', 'communication', 'appearance', 'settings'] as $group) {
            expect($nav['groups'][$group] ?? '')->not->toBe('', "{$locale} ui.manager_nav.groups.{$group}");
        }
        foreach (['plan', 'brand', 'site', 'menu', 'booking_page', 'cart', 'print', 'payments', 'notifications', 'usage'] as $item) {
            expect($nav['items'][$item] ?? '')->not->toBe('', "{$locale} ui.manager_nav.items.{$item}");
        }
        expect(array_key_exists('pro', $nav))->toBeFalse('the hardcoded PRO badge is gone');
    }
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
