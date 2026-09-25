<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
});

it('renders every primary Super Admin surface in each supported locale', function (string $locale, string $direction): void {
    $user = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $user->forceFill(['mfa_confirmed_at' => now()])->save();

    $this->actingAs($user, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get("http://superadmin.localhost:8000/?locale={$locale}")
        ->assertOk();

    foreach (['centers', 'centers/new', 'plans', 'subscriptions', 'billing', 'usage', 'entitlements', 'currencies', 'support', 'operations', 'cms', 'audit', 'settings', 'alerts', 'announcements', 'users', 'roles', 'account'] as $path) {
        $this->get("http://superadmin.localhost:8000/{$path}")
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
            ->assertDontSee('htmlspecialchars(): Argument #1')
            ->assertDontSee('>CKB<', false);
    }
})->with([
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
    'Kurdish Sorani' => ['ckb', 'rtl'],
]);
