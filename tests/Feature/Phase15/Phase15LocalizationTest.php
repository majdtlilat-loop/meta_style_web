<?php

declare(strict_types=1);

use App\Livewire\Auth\RegisterCenter;
use Illuminate\Support\Arr;
use Livewire\Livewire;

it('keeps every Phase 15 translation group structurally aligned and scalar at every leaf', function (): void {
    $groups = [
        'center_auth',
        'phase15_mail',
        'phone_field',
        'platform_auth',
        'platform_branding',
        'platform_landing',
        'platform_public',
        'platform_settings',
        'platform_notifications',
        'platform_permissions',
        'saas_documents',
        'sadmin_alerts',
        'sadmin_audit',
        'sadmin_billing',
        'sadmin_center_users',
        'sadmin_centers',
        'sadmin_cms',
        'sadmin_common',
        'sadmin_currencies',
        'sadmin_dashboard',
        'sadmin_entitlements',
        'sadmin_notifications',
        'sadmin_operations',
        'sadmin_plans',
        'sadmin_roles',
        'sadmin_shell',
        'sadmin_subscriptions',
        'sadmin_support',
        'sadmin_usage',
        'sadmin_users',
        'superadmin_ui',
    ];

    foreach ($groups as $group) {
        /** @var array<string, mixed> $english */
        $english = require lang_path("en/{$group}.php");
        $expected = Arr::dot($english);

        foreach (['en', 'ar', 'ckb'] as $locale) {
            $path = lang_path("{$locale}/{$group}.php");

            expect($path)->toBeFile();

            /** @var array<string, mixed> $translations */
            $translations = require $path;
            $flattened = Arr::dot($translations);

            expect(array_keys($flattened))->toBe(array_keys($expected));

            foreach ($flattened as $key => $value) {
                expect($value)
                    ->toBeString("{$locale}.{$group}.{$key} must be a scalar string")
                    ->not->toBe('');
            }
        }
    }
});

it('renders corporate and platform authentication shells in every supported direction', function (
    string $locale,
    string $direction,
    string $visibleAbbreviation,
): void {
    $this->withoutVite();

    $this->get("http://localhost:8000/?locale={$locale}")
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
        ->assertSee(__('platform_landing.hero.title'))
        ->assertDontSee("@yield('content')", false)
        ->assertSee($visibleAbbreviation)
        ->assertDontSee('>CKB<', false);

    $this->get("http://superadmin.localhost:8000/login?locale={$locale}")
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
        ->assertSee($visibleAbbreviation)
        ->assertDontSee('>CKB<', false);
})->with([
    'English' => ['en', 'ltr', 'EN'],
    'Arabic' => ['ar', 'rtl', 'AR'],
    'Kurdish Sorani' => ['ckb', 'rtl', 'KU'],
]);

it('keeps all static Phase 15 translation calls on non-empty scalar leaf keys', function (): void {
    $roots = [
        resource_path('views/layouts/superadmin'),
        resource_path('views/layouts/platform-public'),
        resource_path('views/livewire/sadmin'),
        resource_path('views/livewire/auth'),
        resource_path('views/platform'),
        resource_path('views/mail'),
        app_path('Livewire/Sadmin'),
        app_path('Livewire/Auth'),
    ];

    $keys = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            preg_match_all("/__\\(\s*['\"]([a-z][a-z0-9_]*\.[^'\"]+)['\"]/", $contents, $matches);
            $keys = [
                ...$keys,
                ...array_filter($matches[1], fn (string $key): bool => ! str_ends_with($key, '.')),
            ];
        }
    }

    foreach (['en', 'ar', 'ckb'] as $locale) {
        app()->setLocale($locale);

        foreach (array_unique($keys) as $key) {
            $value = __($key);

            expect($value)
                ->toBeString("{$locale}.{$key} must resolve to a scalar string")
                ->not->toBe($key)
                ->not->toBe('');
        }
    }
});

it('defaults a new center registration to the actively selected UI locale', function (string $locale): void {
    app()->setLocale($locale);

    Livewire::test(RegisterCenter::class)->assertSet('locale', $locale);
})->with(['en', 'ar', 'ckb']);
