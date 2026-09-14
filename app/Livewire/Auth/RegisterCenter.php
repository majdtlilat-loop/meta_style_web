<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\SaaS\RegistrationSession;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Center sign-up.
 *
 * Deliberately five fields. Everything a center needs to actually operate is
 * asked for after they have an account, by the Phase 4 setup wizard — a sign-up
 * that doubles as a data-entry session loses the customer.
 */
#[Layout('components.layouts.app')]
final class RegisterCenter extends Component
{
    public string $centerName = '';

    public string $ownerName = '';

    public string $email = '';

    public string $password = '';

    public string $locale = 'en';

    public function submit(RegistrationService $registrations): mixed
    {
        $validated = $this->validate([
            'centerName' => ['required', 'string', 'min:2', 'max:190'],
            'ownerName' => ['required', 'string', 'min:2', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', Password::min(10)->uncompromised()],
            'locale' => ['required', 'string', 'in:en,ar,ckb'],
        ]);

        $result = $registrations->register([
            'center_name' => $validated['centerName'],
            'owner_name' => $validated['ownerName'],
            'owner_email' => $validated['email'],
            'password' => $validated['password'],
            'locale' => $validated['locale'],
        ], 'web:'.substr(hash('sha256', mb_strtolower($validated['email']).'|'.$validated['centerName']), 0, 100));

        // The password is gone from memory as soon as this request ends; it was
        // hashed inside the service and never persisted in plaintext.
        $this->reset('password');

        $registration = $result['registration'];

        // The access token goes in the SESSION, not the redirect URL. A
        // capability in a query string lands in browser history, in the
        // Referer header of every outbound link, and in the web server's
        // access log (ADR-035).
        if ($result['access_token'] !== null) {
            session()->put(RegistrationSession::keyFor($registration->uuid), $result['access_token']);
        }

        return $this->redirectRoute('registration.status', ['uuid' => $registration->uuid], navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.auth.register-center');
    }
}
