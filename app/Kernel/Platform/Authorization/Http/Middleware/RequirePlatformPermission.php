<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Authorization\Http\Middleware;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user('platform');

        abort_unless($user instanceof PlatformUser && $user->hasPermission($permission), 403);

        return $next($request);
    }
}
