<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\RegistrationSession;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Onboarding\Application\RegistrationService;
use App\Modules\Onboarding\Domain\Exceptions\CenterSlugUnavailable;
use Illuminate\Validation\Rule;
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
#[Layout('layouts.platform-public.app')]
final class RegisterCenter extends Component
{
    public string $centerName = '';

    public string $ownerName = '';

    public string $centerSlug = '';

    public string $email = '';

    /** The owner's phone as typed, and its country (Iraq unless changed). */
    public string $phone = '';

    public string $phoneCountry = PhoneCountries::DEFAULT;

    public string $password = '';

    public string $locale = 'en';

    public ?int $planId = null;

    /** The billing cycle chosen on the pricing table; used only when the plan sells it. */
    public string $billingCycle = 'monthly';

    public function mount(LanguageRegistry $languages): void
    {
        if ($languages->supports(app()->getLocale())) {
            $this->locale = app()->getLocale();
        }

        $requested = request()->query('plan');
        if (is_numeric($requested) && Plan::query()->whereKey((int) $requested)->where('is_public', true)->where('is_active', true)->exists()) {
            $this->planId = (int) $requested;
        }

        $cycle = request()->query('cycle');
        if (is_string($cycle) && in_array($cycle, Plan::CYCLES, true)) {
            $this->billingCycle = $cycle;
        }
    }

    public function submit(RegistrationService $registrations): mixed
    {
        $validated = $this->validate([
            'centerName' => ['required', 'string', 'min:2', 'max:190'],
            'centerSlug' => ['required', 'string', 'min:2', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'ownerName' => ['required', 'string', 'min:2', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phoneCountry' => ['required', 'string', 'size:2'],
            'phone' => ['required', 'string', 'max:32', new PhoneRule($this->phoneCountry)],
            'password' => ['required', 'string', Password::min(10)->uncompromised()],
            'locale' => ['required', 'string', Rule::in(app(LanguageRegistry::class)->supported())],
            'planId' => ['nullable', 'integer', 'exists:control.plans,id'],
            'billingCycle' => ['required', Rule::in(Plan::CYCLES)],
        ]);

        // A cycle the chosen plan is not sold on falls back to one it is.
        $plan = $validated['planId'] !== null ? Plan::query()->find($validated['planId']) : null;
        $cycle = $plan instanceof Plan ? ($plan->offers($validated['billingCycle']) ? $validated['billingCycle'] : ($plan->cycles()[0] ?? null)) : null;

        $slug = app(PlatformHosts::class)->normalizeSlug($validated['centerSlug']);

        if (! app(PlatformHosts::class)->isValidCenterSlug($slug)) {
            $this->addError('centerSlug', __('center_auth.registration.slug_unavailable'));

            return null;
        }

        try {
            $result = $registrations->register([
                'center_name' => $validated['centerName'],
                'center_slug' => $slug,
                'owner_name' => $validated['ownerName'],
                'owner_email' => $validated['email'],
                'owner_phone' => (string) PhoneNumber::fromParts($validated['phoneCountry'], $validated['phone'])?->e164,
                'password' => $validated['password'],
                'locale' => $validated['locale'],
                'plan_id' => $validated['planId'],
                'cycle' => $cycle,
            ], 'web:'.substr(hash('sha256', mb_strtolower($validated['email']).'|'.$slug), 0, 100));
        } catch (CenterSlugUnavailable) {
            $this->addError('centerSlug', __('center_auth.registration.slug_unavailable'));

            return null;
        }

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

    public function render(PlatformHosts $hosts): mixed
    {
        return view('livewire.auth.register-center', [
            'plans' => Plan::query()->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->get(),
            'baseHost' => $hosts->baseDomain(),
        ]);
    }
}
