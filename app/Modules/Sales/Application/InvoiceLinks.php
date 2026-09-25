<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Sales\Domain\InvoiceShareToken;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mints an invoice's customer link, and composes the URL the customer receives.
 *
 * The one place a share secret is created. It persists the DIGEST and returns
 * the plaintext to its caller — the only copy there will ever be. Nothing can
 * look a secret up again afterwards, which is the point: a staff screen that
 * wants a link for an older invoice issues a new one, and the old one stops
 * working (docs/18-SALES.md §20).
 */
final class InvoiceLinks
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * Creates the invoice's live link. Runs inside the caller's transaction —
     * finalization or rotation — so a link never outlives a rollback.
     *
     * @return string the plaintext secret; never store it, never log it
     */
    public function mint(Invoice $invoice, User $actingUser): string
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('InvoiceLinks::mint() must run inside a transaction.');
        }

        $secret = InvoiceShareToken::generate();

        InvoiceShareLink::query()->create([
            'invoice_id' => $invoice->getKey(),
            'active_invoice_id' => $invoice->getKey(),
            'token_hash' => InvoiceShareToken::hash($secret),
            'created_by_id' => $actingUser->uuid,
            'created_by_label' => $actingUser->name,
        ]);

        return $secret;
    }

    /**
     * The customer's URL for a secret that was just minted.
     *
     * On the center's own host the link is published under that host's slug —
     * the only address a center answers on since Phase 15 (the host resolves
     * through the domains registry, and the route segment must agree with it).
     * Without a host (an API call or a queued job), the center's registered slug —
     * so the link still opens on the center's own host; the public key only for
     * a center registered before hosts existed.
     */
    public function url(#[\SensitiveParameter] string $secret): string
    {
        $center = $this->urls->getDefaultParameters()['center'] ?? null;

        return route('invoice.public', [
            'center' => is_string($center) && $center !== '' ? $center : ($this->tenants->require()->slug ?? $this->tenants->require()->publicKey),
            'token' => $secret,
        ]);
    }
}
