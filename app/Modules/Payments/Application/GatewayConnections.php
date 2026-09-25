<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Domain\Data\GatewayCredentials;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use Illuminate\Routing\UrlGenerator;

/**
 * The only place a gateway account's credentials are decrypted, and the only
 * place its callback URL is composed.
 *
 * Credentials leave here inside a {@see GatewayCredentials}, straight into one
 * adapter call. The callback URL identifies the center (by the host it was
 * started on and that host's slug, or its public key off-host) and the account
 * by its public uuid — neither is a secret, and neither lets anybody settle a
 * payment: a callback is believed only after signature verification or an
 * authenticated status query (docs/19-PAYMENTS.md §21).
 */
final class GatewayConnections
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly TenantContext $tenants,
        private readonly UrlGenerator $urls,
        private readonly PlatformHosts $hosts,
    ) {}

    /**
     * @throws PaymentFailed
     */
    public function provider(GatewayAccount $account): PaymentProvider
    {
        return $this->providers->get($account->provider);
    }

    /**
     * @throws PaymentFailed when the account holds no credentials
     */
    public function credentials(GatewayAccount $account): GatewayCredentials
    {
        // Null also when they no longer decrypt (a rotated application key):
        // the account refuses to operate rather than failing with a 500.
        $values = $account->readableCredentials();

        if ($values === null) {
            throw PaymentFailed::policy('That payment gateway is not configured.');
        }

        return new GatewayCredentials($account->uuid, $account->environment, $values);
    }

    /**
     * Where the provider reports back.
     *
     * Since Phase 15 a center answers public routes only on its own host, with
     * that host's slug repeated in the path (`ResolvePublicTenant`); a URL on
     * the platform host with the public key no longer resolves. A payment is
     * started on the center's host — the till, or the customer's invoice page —
     * whose resolution sets the `center` URL default to the slug, so the
     * callback is published there. Off-host (an API client), the public key as
     * before.
     */
    public function callbackUrl(GatewayAccount $account): string
    {
        $center = $this->urls->getDefaultParameters()['center'] ?? null;
        $slug = is_string($center) && $center !== '' ? $center : $this->tenants->require()->slug;

        if ($slug === null) {
            // A center registered before hosts existed has no address of its own.
            return route('api.payments.webhook', ['center' => $this->tenants->require()->publicKey, 'account' => $account->uuid]);
        }

        // The API routes are not bound to a domain, so the callback is placed on
        // the center's own host explicitly — correct from the Manager, the API,
        // a queue or the command line alike (ADR-097).
        return $this->hosts->centerUrl($slug, route('api.payments.webhook', ['center' => $slug, 'account' => $account->uuid], false));
    }
}
