<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Actions\AuthenticateStaff;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\Exceptions\TooManyLoginAttempts;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff sign-in.
 *
 * The center has already been resolved from its authoritative subdomain before
 * authentication runs. The submitted form contains credentials only and can
 * never choose a tenant.
 *
 * Attempts are limited inside {@see AuthenticateStaff}, not by the route: this
 * action posts to `/livewire/update`, so `throttle:login` on `GET /login` never
 * runs for it. The only limiting this component does itself is for a center key
 * that resolves to nothing, which never reaches the action at all.
 */
#[Layout('components.layouts.app')]
final class Login extends Component
{
    public string $identifier = '';

    public string $password = '';

    /**
     * Laravel's own remember-me on the `web` guard. The recaller cookie is
     * host-only and names its guard, and the token it carries is checked
     * against THIS center's `users` table — so it can never sign anybody in
     * to another center, or to the platform.
     */
    public bool $remember = false;

    public function submit(
        TenantContext $context,
        AuthenticateStaff $authenticate,
    ): mixed {
        $this->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $tenant = $context->require();

        // One failure message for every cause — unknown center, unknown user,
        // wrong password, inactive account. Anything more specific turns this
        // form into a way to discover who works where.
        $rejected = ValidationException::withMessages([
            'identifier' => __('center_auth.errors.credentials'),
        ]);

        try {
            /** @var User $user */
            $user = $authenticate(
                $this->identifier,
                $this->password,
                AuditSource::Web,
            );
        } catch (AuthenticationFailed) {
            throw $rejected;
        } catch (TooManyLoginAttempts) {
            // DELIBERATELY DISTINCT from a credentials failure. Somebody being
            // told to wait needs to know that, or they keep trying and keep
            // extending the block. It discloses nothing about whether the
            // account exists: the bucket counts what was typed, so it trips at
            // the same attempt either way.
            throw ValidationException::withMessages([
                'identifier' => __('center_auth.errors.throttled'),
            ]);
        }

        session()->regenerate();
        session()->put(StanclTenantResolver::SESSION_KEY, (string) $tenant->publicKey);
        Auth::guard('web')->login($user, $this->remember);

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
    public function render(): mixed
    {
        return view('livewire.auth.login', [
            // Self-registration is a platform choice; the link shows only when it exists.
            'registerUrl' => Route::has('register') ? route('register') : null,
        ]);
    }
}
