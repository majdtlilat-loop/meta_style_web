<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Actions\AuthenticateStaff;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Identity\LoginThrottle;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff sign-in.
 *
 * The center key is asked for because the web session has no tenant yet and
 * something has to say which center is being signed into. It is opaque and
 * revocable and authorises nothing on its own — valid credentials in that
 * center's database are still required (docs/02-TENANCY.md §2.2, source 3).
 *
 * Once signed in, the center is remembered in the SESSION — server-side state
 * the caller cannot edit — so no later request has to be told again.
 *
 * Attempts are limited inside {@see AuthenticateStaff}, not by the route: this
 * action posts to `/livewire/update`, so `throttle:login` on `GET /login` never
 * runs for it. The only limiting this component does itself is for a center key
 * that resolves to nothing, which never reaches the action at all.
 */
#[Layout('components.layouts.app')]
final class Login extends Component
{
    public string $centerKey = '';

    public string $identifier = '';

    public string $password = '';

    public function submit(
        TenantResolver $resolver,
        TenantContext $context,
        AuthenticateStaff $authenticate,
        LoginThrottle $throttle,
    ): mixed {
        $this->validate([
            'centerKey' => ['required', 'string', 'max:64'],
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $tenant = $resolver->findByPublicKey($this->centerKey);

        // One failure message for every cause — unknown center, unknown user,
        // wrong password, inactive account. Anything more specific turns this
        // form into a way to discover who works where.
        $rejected = ValidationException::withMessages([
            'identifier' => __('Those credentials do not match our records.'),
        ]);

        try {
            if ($tenant === null) {
                // An unknown CENTER key has to cost what a real one costs.
                // Rate limiting the credential check made "too many attempts"
                // the sixth answer for a real center — so a sixth "those
                // credentials do not match" would confirm the key names no
                // center at all, an oracle this form did not have before.
                //
                // Counted in the tenant-less scope, which no real center
                // shares, so it can neither consume nor be observed through any
                // center's own allowance.
                $throttle->assertAllowed($this->throttleIdentifier());
                $throttle->recordFailure($this->throttleIdentifier());

                throw $rejected;
            }

            /** @var User $user */
            $user = $context->run($tenant, fn (): User => $authenticate(
                $this->identifier,
                $this->password,
                AuditSource::Web,
            ));
        } catch (AuthenticationFailed) {
            throw $rejected;
        } catch (TooManyLoginAttempts) {
            // DELIBERATELY DISTINCT from a credentials failure. Somebody being
            // told to wait needs to know that, or they keep trying and keep
            // extending the block. It discloses nothing about whether the
            // account exists: the bucket counts what was typed, so it trips at
            // the same attempt either way.
            throw ValidationException::withMessages([
                'identifier' => __('Too many attempts. Please wait and try again.'),
            ]);
        }

        session()->regenerate();
        session()->put(StanclTenantResolver::SESSION_KEY, $this->centerKey);

        $context->run($tenant, function () use ($user): void {
            Auth::guard('web')->login($user);
        });

        $this->reset('password');

        return $this->redirectRoute('center.dashboard', navigate: true);
    }

    /**
     * The same spelling {@see AuthenticateStaff} buckets on.
     *
     * Lower-cased here too, so alternating capitalisation cannot buy extra
     * attempts on the unknown-center path that it would not buy on the real
     * one — which would put the difference back that the counting removes.
     */
    private function throttleIdentifier(): string
    {
        return mb_strtolower(trim($this->identifier));
    }

    public function render(): mixed
    {
        return view('livewire.auth.login');
    }
}
