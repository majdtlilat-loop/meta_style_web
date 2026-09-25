<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Auth;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Platform\Identity\Actions\AuthenticatePlatform;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Identity\PlatformLanding;
use App\Kernel\Platform\Settings\PlatformPreferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.superadmin.auth')]
final class Login extends Component
{
    public const PENDING_USER_KEY = 'metastyle.platform.pending_user';

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): mixed
    {
        return Auth::guard('platform')->check()
            ? $this->redirectRoute(PlatformLanding::routeFor(Auth::guard('platform')->user()), navigate: true)
            : null;
    }

    public function submit(AuthenticatePlatform $authenticate, PlatformPreferences $preferences, Audit $audit): mixed
    {
        $validated = $this->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        try {
            $user = $authenticate($validated['email'], $validated['password']);
        } catch (TooManyLoginAttempts) {
            throw ValidationException::withMessages(['email' => __('platform_auth.errors.throttled')]);
        } catch (AuthenticationFailed) {
            throw ValidationException::withMessages(['email' => __('platform_auth.errors.credentials')]);
        }

        session()->regenerate();

        // Enforcement switched off by a Super Admin: the password (throttled
        // and audited in AuthenticatePlatform) is the whole sign-in.
        if (! $preferences->mfaRequired()) {
            Auth::guard('platform')->login($user, (bool) $validated['remember']);
            session()->regenerate();
            $this->reset('password');
            $audit->record(new AuditEvent(
                action: 'platform.identity.login.succeeded',
                category: AuditCategory::Security,
                actor: Actor::platform($user),
                targetType: PlatformUser::class,
                targetId: $user->uuid,
                meta: ['mfa' => 'not_required'],
            ));

            return $this->redirectRoute(PlatformLanding::routeFor($user), navigate: true);
        }

        session()->put(self::PENDING_USER_KEY, $user->getKey());
        session()->put('metastyle.platform.remember', $validated['remember']);
        $this->reset('password');

        return $this->redirectRoute(
            $user->hasConfirmedMfa() ? 'superadmin.mfa.challenge' : 'superadmin.mfa.setup',
            navigate: true,
        );
    }

    public function render(): mixed
    {
        return view('livewire.sadmin.auth.login');
    }
}
