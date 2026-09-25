<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Auth;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Identity\PlatformLanding;
use App\Kernel\Platform\Identity\PlatformMfa;
use App\Kernel\Platform\Settings\PlatformPreferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.superadmin.auth')]
final class MfaChallenge extends Component
{
    use ResolvesMfaCandidate;

    public string $code = '';

    public bool $useRecoveryCode = false;

    public function mount(): mixed
    {
        if ($this->alreadyVerified() || (Auth::guard('platform')->check() && ! app(PlatformPreferences::class)->mfaRequired())) {
            return $this->redirectRoute(PlatformLanding::routeFor(Auth::guard('platform')->user()), navigate: true);
        }

        if (! $this->mfaCandidate() instanceof PlatformUser) {
            return $this->redirectRoute('superadmin.login', navigate: true);
        }

        return null;
    }

    public function submit(PlatformMfa $mfa, Audit $audit): mixed
    {
        $this->validate(['code' => ['required', 'string', 'max:32']]);
        $user = $this->mfaCandidate();

        abort_unless($user instanceof PlatformUser && $user->hasConfirmedMfa(), 403);

        $valid = $this->useRecoveryCode
            ? $mfa->consumeRecoveryCode($user, $this->code)
            : $mfa->verify((string) $user->mfa_secret, $this->code);

        if (! $valid) {
            throw ValidationException::withMessages(['code' => __('platform_auth.errors.mfa')]);
        }

        // Only the password path signs in here. A remembered user is already
        // signed in by the cookie; signing in again would drop that cookie.
        if ($this->isPendingUser($user)) {
            Auth::guard('platform')->login($user, (bool) session()->pull('metastyle.platform.remember', false));
            session()->forget(Login::PENDING_USER_KEY);
        }

        session()->put(EnsurePlatformMfa::SESSION_KEY, now()->timestamp);
        session()->regenerate();

        $audit->record(new AuditEvent(
            action: 'platform.identity.mfa.succeeded',
            category: AuditCategory::Security,
            actor: Actor::platform($user),
            targetType: PlatformUser::class,
            targetId: $user->uuid,
        ));

        return $this->redirectRoute(PlatformLanding::routeFor($user), navigate: true);
    }

    public function render(): mixed
    {
        // No authorization here: mount() redirects anyone without a candidate,
        // and submit() re-resolves and refuses on its own.
        return view('livewire.sadmin.auth.mfa-challenge');
    }
}
