<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\RegistrationSession;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Onboarding\Application\RegistrationService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Polls a registration while its center is being built, and offers a retry.
 *
 * Provisioning creates a database and runs migrations, so there is a real wait
 * here — and it can fail. Showing "preparing" honestly beats pretending the
 * account exists (docs/02-TENANCY.md §8.2).
 *
 * The uuid in the URL identifies the registration but authorises nothing. The
 * access token issued at submission — held in this browser's session, never in
 * the URL — is what opens it (ADR-035). Someone who is handed this link sees
 * exactly what someone who guessed the uuid sees: nothing.
 */
#[Layout('components.layouts.app')]
final class RegistrationStatus extends Component
{
    public string $uuid = '';

    public string $notice = '';

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    /**
     * Re-queues provisioning after a failure.
     *
     * Guarded twice over: the session must hold this registration's token, and
     * `retry()` refuses anything that is not a failed registration inside its
     * credential window, so a stale page or a double click cannot queue
     * provisioning twice (ADR-031).
     */
    public function retry(RegistrationService $registrations): void
    {
        $registration = $this->registration();

        if (! $registration instanceof Registration) {
            return;
        }

        $this->notice = $registrations->retry($registration)
            ? __('Trying again…')
            : __('This registration can no longer be retried. Please register again.');
    }

    public function render(): mixed
    {
        $registration = $this->registration();

        return view('livewire.auth.registration-status', [
            'registration' => $registration,
            // An unknown uuid and an unauthorised one render identically. The
            // page must not become a way to confirm a registration exists.
            'status' => $registration?->status->value ?? 'unknown',
            'retryable' => $registration?->isRetryable() ?? false,
            'centerKey' => $registration?->tenant_id === null
                ? null
                : TenantModel::query()
                    ->whereKey($registration->tenant_id)->value('public_key'),
        ]);
    }

    /**
     * The registration, but only for a browser that holds its capability.
     */
    private function registration(): ?Registration
    {
        $registration = Registration::query()->where('uuid', $this->uuid)->first();

        if (! $registration instanceof Registration) {
            return null;
        }

        $token = session()->get(RegistrationSession::keyFor($this->uuid));

        return $registration->accessTokenMatches(is_string($token) ? $token : null)
            ? $registration
            : null;
    }
}
