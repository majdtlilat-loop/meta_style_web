<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Modules\Customers\Application\Actions\AuthenticateCustomer;
use App\Modules\Customers\Application\Actions\RegisterCustomerAccount;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A customer signing up or signing in to a center.
 *
 * CENTRAL, with no tenant bound when it loads. The center is named by a
 * `center_key` in the form and recorded in the session on success — the same
 * trusted path staff login uses (ADR-030). It is deliberately NOT resolved from
 * the URL path: `ResolvePublicTenant` accepts a public key from a path, and that
 * middleware may never sit on a route that authenticates anybody (ADR-036), so
 * keeping customer sign-in here preserves that boundary intact.
 *
 * The menu links here with `?center=` to pre-fill the field. That is a form
 * default, not a resolution source: what actually resolves is the submitted key.
 *
 * On success it REDIRECTS to the account page, which does carry `tenant`. The
 * work happens inside a tenant closure and the tenant is unbound the moment it
 * returns, so nothing after the redirect may touch tenant data in this request.
 */
#[Layout('components.layouts.app')]
final class SignIn extends Component
{
    public string $mode = 'login';

    public string $centerKey = '';

    public string $phone = '';

    public string $password = '';

    public string $name = '';

    public string $error = '';

    public function mount(?string $center = null): void
    {
        $this->centerKey = $center ?? (string) session(StanclTenantResolver::SESSION_KEY, '');
    }

    public function login(
        TenantResolver $resolver,
        TenantContext $context,
        AuthenticateCustomer $authenticate,
    ): mixed {
        $this->validate([
            'centerKey' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $resolver->findByPublicKey($this->centerKey);

        if ($tenant === null) {
            // The same answer bad credentials get: anything else would confirm
            // which center keys are real.
            return $this->fail();
        }

        try {
            $context->run($tenant, function () use ($authenticate): void {
                $account = $authenticate($this->phone, $this->password);

                // Inside the tenant closure, because the guard reads the
                // account to write its id into the session. Writing the session
                // itself touches no database, so the redirect below is safe.
                $this->signIn($account);
            });
        } catch (AuthenticationFailed) {
            return $this->fail();
        } catch (TooManyLoginAttempts) {
            // DELIBERATELY DISTINCT from a credentials failure. Somebody who is
            // being told to wait needs to know that, or they keep trying and
            // keep extending the block. It discloses nothing about whether the
            // account exists: the bucket counts what was typed, so it trips at
            // the same point either way (Phase 6 §1).
            return $this->tooManyAttempts();
        } catch (EntitlementRequired) {
            return $this->accountsUnavailable();
        }

        return $this->redirectRoute('customer.account', navigate: true);
    }

    public function register(
        TenantResolver $resolver,
        TenantContext $context,
        RegisterCustomerAccount $register,
    ): mixed {
        $this->validate([
            'centerKey' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'name' => ['required', 'string', 'max:190'],
        ]);

        $tenant = $resolver->findByPublicKey($this->centerKey);

        if ($tenant === null) {
            return $this->fail();
        }

        try {
            $context->run($tenant, function () use ($register): void {
                $result = $register($this->phone, $this->password, $this->name, app()->getLocale());

                $this->signIn($result['account']);
            });
        } catch (EntitlementRequired) {
            return $this->accountsUnavailable();
        } catch (ValidationException $e) {
            $this->error = $e->getMessage();
            $this->password = '';

            return null;
        }

        return $this->redirectRoute('customer.account', navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.customer.sign-in');
    }

    private function signIn(CustomerAccount $account): void
    {
        // The session records the CENTER, which is what `ResolveTenant` reads
        // on every later request (ADR-030).
        session()->put(StanclTenantResolver::SESSION_KEY, $this->centerKey);
        session()->regenerate();

        Auth::guard('customer')->login($account);

        $this->password = '';
        $this->error = '';
    }

    private function tooManyAttempts(): null
    {
        $this->error = __('Too many attempts. Please wait and try again.');
        $this->password = '';

        return null;
    }

    private function fail(): null
    {
        $this->error = __('Those details do not match our records.');
        $this->password = '';

        return null;
    }

    private function accountsUnavailable(): null
    {
        $this->error = __('This center does not offer customer accounts.');
        $this->password = '';

        return null;
    }
}
