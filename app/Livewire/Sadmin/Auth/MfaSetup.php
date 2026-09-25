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
final class MfaSetup extends Component
{
    use ResolvesMfaCandidate;

    public string $code = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

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

    public function confirm(PlatformMfa $mfa, Audit $audit): void
    {
        $this->validate(['code' => ['required', 'digits:6']]);
        $user = $this->mfaCandidate();
        abort_unless($user instanceof PlatformUser && is_string($user->mfa_secret), 403);

        if (! $mfa->verify($user->mfa_secret, $this->code)) {
            throw ValidationException::withMessages(['code' => __('platform_auth.errors.mfa')]);
        }

        $this->recoveryCodes = $mfa->generateRecoveryCodes();
        $user->forceFill([
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => array_map(static fn (string $value): string => hash('sha256', $value), $this->recoveryCodes),
        ])->save();

        if ($this->isPendingUser($user)) {
            Auth::guard('platform')->login($user, (bool) session()->pull('metastyle.platform.remember', false));
            session()->forget(Login::PENDING_USER_KEY);
        }

        session()->put(EnsurePlatformMfa::SESSION_KEY, now()->timestamp);
        session()->regenerate();

        $audit->record(new AuditEvent(
            action: 'platform.identity.mfa.enrolled',
            category: AuditCategory::Security,
            actor: Actor::platform($user),
            targetType: PlatformUser::class,
            targetId: $user->uuid,
        ));
    }

    public function render(PlatformMfa $mfa): mixed
    {
        // Once enrolment has completed in this request the user is verified;
        // before that, the candidate is the only person this page is for.
        $user = $this->mfaCandidate() ?? Auth::guard('platform')->user();
        $secret = $user instanceof PlatformUser && is_string($user->mfa_secret) ? $user->mfa_secret : '';

        return view('livewire.sadmin.auth.mfa-setup', [
            'secret' => $secret,
            'provisioningUri' => $user instanceof PlatformUser && $secret !== '' ? $mfa->provisioningUri($user, $secret) : '',
        ]);
    }
}
