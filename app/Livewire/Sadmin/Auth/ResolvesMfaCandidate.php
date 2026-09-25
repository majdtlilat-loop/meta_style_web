<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Auth;

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Illuminate\Support\Facades\Auth;

/**
 * Who is being asked for a second factor.
 *
 * Two people reach the MFA screens:
 *
 *   - someone who has just passed the password step: their id is held in the
 *     session as the PENDING user and they are not signed in yet;
 *   - someone the "remember me" cookie has already signed back in to a fresh
 *     session. The cookie stands in for the PASSWORD only — this session has
 *     never passed MFA, so `EnsurePlatformMfa` sends them here.
 *
 * Before the second case existed, a remembered Super Admin bounced between
 * `EnsurePlatformMfa` (go to the challenge) and the challenge's `guest`
 * middleware (you are signed in, go to the dashboard) forever. Remember-me
 * never skips MFA: both paths still need a valid code.
 */
trait ResolvesMfaCandidate
{
    protected function mfaCandidate(): ?PlatformUser
    {
        $candidate = $this->pendingUser() ?? $this->rememberedUser();

        return $candidate instanceof PlatformUser && $candidate->is_active && $candidate->archived_at === null ? $candidate : null;
    }

    protected function isPendingUser(PlatformUser $user): bool
    {
        return $this->pendingUser()?->is($user) ?? false;
    }

    protected function alreadyVerified(): bool
    {
        return Auth::guard('platform')->check() && is_numeric(session()->get(EnsurePlatformMfa::SESSION_KEY));
    }

    private function pendingUser(): ?PlatformUser
    {
        $id = session()->get(Login::PENDING_USER_KEY);

        return is_numeric($id) ? PlatformUser::query()->find((int) $id) : null;
    }

    /**
     * Signed in, but not through MFA in THIS session.
     */
    private function rememberedUser(): ?PlatformUser
    {
        if (is_numeric(session()->get(EnsurePlatformMfa::SESSION_KEY))) {
            return null;
        }

        $user = Auth::guard('platform')->user();

        return $user instanceof PlatformUser ? $user : null;
    }
}
