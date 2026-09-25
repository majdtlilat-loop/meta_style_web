<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Auth;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Redeems a platform password link: an invitation or a reset.
 *
 * The address is typed, never carried in the link (no personal data in a
 * URL). A successful reset signs nobody in — the person then signs in
 * normally, second factor included when the platform requires it.
 */
#[Layout('layouts.superadmin.auth')]
final class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function submit(Audit $audit): mixed
    {
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'same:passwordConfirmation', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
            'passwordConfirmation' => ['required', 'string'],
        ], [], [
            'email' => __('platform_auth.email'),
            'password' => __('platform_auth.new_password'),
            'passwordConfirmation' => __('platform_auth.confirm_password'),
        ]);

        $email = Str::lower(trim($this->email));
        /** @var PlatformUser|null $candidate */
        $candidate = PlatformUser::query()->where('email', $email)->first();
        $broker = $candidate?->password_changed_at === null ? 'platform_invitations' : 'platform';

        $status = Password::broker($broker)->reset(
            ['email' => $email, 'password' => $this->password, 'token' => $this->token],
            function (PlatformUser $user, string $password) use ($audit): void {
                if (! $user->is_active || $user->archived_at !== null) {
                    return;
                }
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();
                $audit->record(new AuditEvent(
                    action: 'platform.identity.password.reset',
                    category: AuditCategory::Security,
                    actor: Actor::platform($user),
                    severity: AuditSeverity::Notice,
                    targetType: PlatformUser::class,
                    targetId: $user->uuid,
                    targetLabel: $user->name,
                ));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('platform_auth.errors.reset')]);
        }

        $this->reset('password', 'passwordConfirmation');
        session()->flash('notice', __('platform_auth.reset_done'));

        return $this->redirectRoute('superadmin.login', navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.sadmin.auth.reset-password');
    }
}
