<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Sales\Domain\InvoiceShareToken;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
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
    public function __construct(private readonly TenantContext $tenants) {}

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
     */
    public function url(#[\SensitiveParameter] string $secret): string
    {
        return route('invoice.public', [
            'center' => $this->tenants->require()->publicKey,
            'token' => $secret,
        ]);
    }
}
