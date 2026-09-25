<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\Identity\Actions\ManageStaffActivation;
use App\Kernel\Identity\Exceptions\InvalidActivationToken;
use App\Kernel\Identity\Models\User;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Where a new member of staff sets their first password.
 *
 * The link a manager hands over (`/activate/{token}`) is the only credential:
 * `ManageStaffActivation::redeem()` checks it under a row lock, uses it once
 * and sets the password; this page never logs, flashes or stores the token and
 * never signs the person in — they sign in themselves, so the ordinary sign-in
 * throttle and audit apply. The route is guest-only and throttled; a Livewire
 * submit does not pass through route middleware, so the Action's single-use
 * lock is what actually stops a replay.
 */
#[Layout('components.layouts.app')]
final class ActivateAccount extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function submit(ManageStaffActivation $activation): mixed
    {
        $this->validate([
            'password' => ['required', 'string', 'max:190', 'same:passwordConfirmation', Password::min(10)->letters()->numbers()],
            'passwordConfirmation' => ['required', 'string'],
        ], [], [
            'password' => __('manager_staff.activation.password'),
            'passwordConfirmation' => __('manager_staff.activation.confirm'),
        ]);

        try {
            $activation->redeem($this->token, $this->password);
        } catch (InvalidActivationToken) {
            $this->reset('password', 'passwordConfirmation');

            throw ValidationException::withMessages(['password' => __('manager_staff.activation.invalid')]);
        }

        $this->reset('password', 'passwordConfirmation');
        session()->flash('password_reset', __('manager_staff.activation.done_notice'));

        return $this->redirectRoute('login', navigate: true);
    }

    public function render(ManageStaffActivation $activation): mixed
    {
        $user = $activation->pending($this->token);

        return view('livewire.auth.activate-account', [
            'valid' => $user instanceof User,
            'firstName' => $user instanceof User ? explode(' ', trim($user->name))[0] : '',
        ])->title(__('manager_staff.activation.title'));
    }
}
