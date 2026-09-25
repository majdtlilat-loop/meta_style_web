<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Conversations\Contracts\MessagingProvider;
use App\Modules\Conversations\Domain\Data\WhatsAppCredentials;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Illuminate\Routing\UrlGenerator;

/**
 * The only place a WhatsApp account's credentials are decrypted, and the only
 * place its webhook URL is composed.
 *
 * Credentials leave here inside a {@see WhatsAppCredentials} and go straight
 * into one adapter call. The webhook URL identifies the center by its public
 * key and the account by its public uuid — neither a secret, and neither enough
 * to do anything: an inbound notification is believed only after its signature
 * verifies against the center's app secret (docs/25-WHATSAPP.md §§4, 8).
 *
 * Deliberately the same class as `Payments\Application\GatewayConnections`,
 * one integration over.
 */
final class WhatsAppConnections
{
    public function __construct(
        private readonly MessagingProviderRegistry $providers,
        private readonly TenantContext $tenants,
        private readonly UrlGenerator $urls,
        private readonly PlatformHosts $hosts,
    ) {}

    /**
     * @throws ConversationFailed
     */
    public function provider(WhatsAppAccount $account): MessagingProvider
    {
        return $this->providers->get($account->provider);
    }

    /**
     * @throws ConversationFailed when the account holds no usable credentials
     */
    public function credentials(WhatsAppAccount $account): WhatsAppCredentials
    {
        // Null also when they no longer decrypt after an application key
        // change: the account refuses to operate rather than failing with a
        // 500, and a manager re-enters them.
        $values = $account->readableCredentials();

        if ($values === null || $account->phone_number_id === null) {
            throw ConversationFailed::notConfigured();
        }

        return new WhatsAppCredentials($account->uuid, $account->phone_number_id, $values);
    }

    /**
     * The URL a center enters in their Meta app's webhook configuration.
     *
     * Safe to display and safe to copy: it carries the center's PUBLIC key and
     * the account's PUBLIC uuid, and knowing both lets somebody send a
     * notification that will be rejected for want of a signature.
     *
     * Since Phase 15 a center answers public routes only on its OWN host, with
     * that host's slug repeated in the path (`ResolvePublicTenant`); a URL
     * carrying the public key does not resolve there. On the center's host —
     * the Manager settings page, a Livewire update, the tenant API — resolution
     * has set the `center` URL default to the slug, so the address is published
     * there. Off-host (CLI, queue), the center's registered slug (ADR-097), on
     * the center's own host; the public key only for a center registered before
     * hosts existed. The same rule as
     * `Payments\Application\GatewayConnections::callbackUrl()`.
     */
    public function webhookUrl(WhatsAppAccount $account): string
    {
        $center = $this->urls->getDefaultParameters()['center'] ?? null;
        $slug = is_string($center) && $center !== '' ? $center : $this->tenants->require()->slug;

        if ($slug === null) {
            // A center registered before hosts existed has no address of its own.
            return route('api.whatsapp.webhook', ['center' => $this->tenants->require()->publicKey, 'account' => $account->uuid]);
        }

        // The webhook route is not bound to a domain, so it is placed on the
        // center's own host explicitly — correct from the Manager, the API, a
        // queue or the command line alike.
        return $this->hosts->centerUrl($slug, route('api.whatsapp.webhook', ['center' => $slug, 'account' => $account->uuid], false));
    }

    /**
     * The account an inbound notification belongs to, by its public uuid.
     *
     * Disabled accounts are INCLUDED, deliberately. A center that has switched
     * their bot off still receives status callbacks for messages already in
     * flight, and refusing those would leave outbound rows stuck `pending`
     * forever — the same reason a disabled gateway account still verifies
     * payment callbacks (§8).
     */
    public function find(string $accountUuid): ?WhatsAppAccount
    {
        /** @var WhatsAppAccount|null $account */
        $account = WhatsAppAccount::query()->where('uuid', $accountUuid)->first();

        return $account;
    }
}
