<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Account;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Platform\Identity\Models\PlatformRole;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Settings\PlatformPreferences;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** The signed-in platform user's own account. Open to every platform user. */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function changePassword(Audit $audit): void
    {
        $user = $this->user();
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'same:newPasswordConfirmation', Password::min(12)->letters()->mixedCase()->numbers()],
            'newPasswordConfirmation' => ['required', 'string'],
        ], [], [
            'currentPassword' => __('sadmin_shell.account.current_password'),
            'newPassword' => __('sadmin_shell.account.new_password'),
            'newPasswordConfirmation' => __('sadmin_shell.account.confirm_password'),
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', __('sadmin_shell.account.password_wrong'));

            return;
        }

        $user->forceFill(['password' => $this->newPassword, 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');

        $audit->record(new AuditEvent(
            action: 'platform.identity.password.changed',
            category: AuditCategory::Security,
            actor: Actor::platform($user),
            severity: AuditSeverity::Notice,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
        ));
        session()->flash('notice', __('sadmin_shell.account.password_saved'));
    }

    public function render(PlatformPreferences $preferences): mixed
    {
        $user = $this->user();

        return view('livewire.sadmin.account.index', [
            'user' => $user,
            // The signed-in person's own sign-in email.
            'email' => $user->email,
            'roles' => $user->roles()->get()->map(fn (PlatformRole $role): string => $role->label())->all(),
            'permissions' => $user->permissions(),
            'mfaRequired' => $preferences->mfaRequired(),
        ]);
    }

    private function user(): PlatformUser
    {
        $user = auth('platform')->user();
        abort_unless($user instanceof PlatformUser, 403);

        return $user;
    }
}
