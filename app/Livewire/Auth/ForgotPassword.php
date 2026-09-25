<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Identity\Application\Actions\RequestCenterPasswordReset;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class ForgotPassword extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function submit(RequestCenterPasswordReset $requestReset, PlatformHosts $hosts): void
    {
        $this->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $slug = $hosts->centerSlugFromHost(request()->getHost());
        abort_unless($slug !== null, 404);
        $key = 'center-password-reset:'.hash('sha256', $slug.'|'.Str::lower($this->email).'|'.request()->ip());
        if (! RateLimiter::tooManyAttempts($key, 5)) {
            RateLimiter::hit($key, 60);
            $requestReset($this->email, $slug);
        }
        $this->sent = true;
        $this->reset('email');
    }

    public function render(): mixed
    {
        return view('livewire.auth.forgot-password');
    }
}
