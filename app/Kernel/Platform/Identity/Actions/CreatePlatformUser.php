<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Actions;

use App\Kernel\Platform\Authorization\PlatformRoleSynchroniser;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Identity\PlatformMfa;
use Illuminate\Support\Facades\DB;

final class CreatePlatformUser
{
    public function __construct(
        private readonly PlatformRoleSynchroniser $roles,
        private readonly PlatformMfa $mfa,
    ) {}

    public function __invoke(string $name, string $email, string $password): PlatformUser
    {
        return DB::connection('control')->transaction(function () use ($name, $email, $password): PlatformUser {
            $role = $this->roles->sync();

            /** @var PlatformUser $user */
            $user = PlatformUser::query()->create([
                'name' => trim($name),
                'email' => mb_strtolower(trim($email)),
                'password' => $password,
                'is_active' => true,
                'mfa_secret' => $this->mfa->generateSecret(),
                'password_changed_at' => now(),
            ]);

            $user->roles()->sync([$role->id]);

            return $user;
        });
    }
}
