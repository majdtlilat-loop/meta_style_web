<?php

declare(strict_types=1);

use App\Kernel\Platform\Authorization\PlatformRoleSynchroniser;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\Hash;

const LOCAL_PLATFORM_ADMIN_EMAIL = 'admin@meta-style.local';
const LOCAL_PLATFORM_ADMIN_PASSWORD = 'MetaStyle@123456';

it('idempotently seeds one local Super Admin with hashed credentials and pending MFA enrollment', function (): void {
    PlatformUser::query()->where('email', LOCAL_PLATFORM_ADMIN_EMAIL)->delete();

    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);

    $user = PlatformUser::query()->where('email', LOCAL_PLATFORM_ADMIN_EMAIL)->firstOrFail();
    $originalId = $user->getKey();
    $originalHash = $user->password;

    expect($user->name)->toBe('Meta Style Super Admin')
        ->and($user->password)->not->toBe(LOCAL_PLATFORM_ADMIN_PASSWORD)
        ->and(Hash::check(LOCAL_PLATFORM_ADMIN_PASSWORD, $user->password))->toBeTrue()
        ->and($user->roles()->pluck('key')->all())->toBe([PlatformRoleSynchroniser::SUPER_ADMIN])
        ->and($user->mfa_secret)->toBeString()->not->toBeEmpty()
        ->and($user->mfa_confirmed_at)->toBeNull()
        ->and($user->hasConfirmedMfa())->toBeFalse();

    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);

    $reseeded = PlatformUser::query()->where('email', LOCAL_PLATFORM_ADMIN_EMAIL)->firstOrFail();

    expect(PlatformUser::query()->where('email', LOCAL_PLATFORM_ADMIN_EMAIL)->count())->toBe(1)
        ->and($reseeded->getKey())->toBe($originalId)
        ->and($reseeded->password)->toBe($originalHash)
        ->and($reseeded->roles()->pluck('key')->all())->toBe([PlatformRoleSynchroniser::SUPER_ADMIN]);
});

it('never seeds the local Super Admin in production', function (): void {
    PlatformUser::query()->where('email', LOCAL_PLATFORM_ADMIN_EMAIL)->delete();
    $application = app();

    try {
        $application->detectEnvironment(static fn (): string => 'production');
        app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);

        expect(PlatformUser::query()->where('email', LOCAL_PLATFORM_ADMIN_EMAIL)->exists())->toBeFalse();
    } finally {
        $application->detectEnvironment(static fn (): string => 'testing');
    }
});
