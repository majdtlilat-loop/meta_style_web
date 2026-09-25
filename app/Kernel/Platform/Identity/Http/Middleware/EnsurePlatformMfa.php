<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Http\Middleware;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Settings\PlatformPreferences;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformMfa
{
    public const SESSION_KEY = 'metastyle.platform.mfa_verified_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('platform');

        abort_unless($user instanceof PlatformUser && $user->is_active && $user->archived_at === null, 403);

        // A Super Admin may switch enforcement off (audited). Enrolled secrets
        // are kept, so switching it back on restores this check unchanged.
        if (! app(PlatformPreferences::class)->mfaRequired()) {
            return $next($request);
        }

        if (! $user->hasConfirmedMfa()) {
            return redirect()->route('superadmin.mfa.setup');
        }

        if (! is_numeric($request->session()->get(self::SESSION_KEY))) {
            return redirect()->route('superadmin.mfa.challenge');
        }

        return $next($request);
    }
}
