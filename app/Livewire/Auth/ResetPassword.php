<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Modules\Identity\Application\Actions\ResetCenterPassword;
use DomainException;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
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

    public function submit(ResetCenterPassword $reset): mixed
    {
        $data = $this->validate(['email' => ['required', 'email:rfc', 'max:190'], 'password' => ['required', 'same:passwordConfirmation', Password::min(10)->letters()->numbers()], 'passwordConfirmation' => ['required', 'string']]);
        try {
            $reset($data['email'], $this->token, $data['password']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['email' => __('center_auth.errors.reset_failed')]);
        }
        session()->flash('password_reset', __('center_auth.reset.updated'));

        return $this->redirectRoute('login', navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.auth.reset-password');
    }
}
