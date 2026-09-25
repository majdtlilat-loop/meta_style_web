<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Concerns;

use App\Kernel\Platform\Identity\Models\PlatformUser;

trait AuthorizesPlatform
{
    private function requirePlatformPermission(string $permission): PlatformUser
    {
        $user = auth('platform')->user();
        abort_unless($user instanceof PlatformUser && $user->hasPermission($permission), 403);

        return $user;
    }
}
