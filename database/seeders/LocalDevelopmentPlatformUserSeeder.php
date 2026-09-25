<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\Platform\Authorization\PlatformRoleSynchroniser;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Identity\PlatformMfa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class LocalDevelopmentPlatformUserSeeder extends Seeder
{
    private const EMAIL = 'admin@meta-style.local';

    private const PASSWORD = 'MetaStyle@123456';

    public function run(PlatformRoleSynchroniser $roles, PlatformMfa $mfa): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            return;
        }

        $role = $roles->sync();
        $user = PlatformUser::query()->firstOrNew(['email' => self::EMAIL]);

        $user->forceFill([
            'name' => 'Meta Style Super Admin',
            'email' => self::EMAIL,
            'is_active' => true,
            'password_changed_at' => $user->password_changed_at ?? now(),
        ]);

        if (! $user->exists || ! Hash::check(self::PASSWORD, (string) $user->password)) {
            // PlatformUser's `hashed` cast performs the one-way hash. The
            // plaintext exists only as this local-development seed value.
            $user->password = self::PASSWORD;
            $user->password_changed_at = now();
        }

        if (! is_string($user->mfa_secret) || $user->mfa_secret === '') {
            $user->mfa_secret = $mfa->generateSecret();
            $user->mfa_confirmed_at = null;
            $user->mfa_recovery_codes = null;
        }

        $user->save();
        $user->roles()->sync([$role->id]);
    }
}
