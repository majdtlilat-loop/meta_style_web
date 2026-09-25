<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Auth;

use App\Kernel\Platform\Identity\Mail\PlatformAccessEmail;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A platform user asks for a password link.
 *
 * The answer is the same whether or not the address has an account, so the
 * form cannot be used to discover who works on the platform. Limited per
 * address and per network, in the action — a Livewire request never reaches a
 * route throttle.
 */
#[Layout('layouts.superadmin.auth')]
final class ForgotPassword extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function submit(): void
    {
        $this->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $email = Str::lower(trim($this->email));
        $key = 'platform-password-link:'.hash('sha256', $email.'|'.request()->ip());

        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 600);
            /** @var PlatformUser|null $user */
            $user = PlatformUser::query()->where('email', $email)->where('is_active', true)->whereNull('archived_at')->first();
            if ($user instanceof PlatformUser) {
                $broker = $user->password_changed_at === null ? 'platform_invitations' : 'platform';
                $token = Password::broker($broker)->createToken($user);
                Mail::to($user->email)->queue(new PlatformAccessEmail($user->name, 'reset', route('superadmin.password.reset', ['token' => $token])));
            }
        }

        $this->sent = true;
        $this->reset('email');
    }

    public function render(): mixed
    {
        return view('livewire.sadmin.auth.forgot-password');
    }
}
