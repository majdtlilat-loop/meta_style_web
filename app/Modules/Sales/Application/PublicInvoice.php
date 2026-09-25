<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\InvoiceShareToken;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * The customer's digital invoice, reached by its opaque share token only.
 *
 * Read-only and unauthenticated. The center was resolved from its public key by
 * `ResolvePublicTenant`; this resolves the invoice from a 256-bit token and
 * NOTHING else — never a number, never an id — so walking the URL space finds
 * nothing. An unknown or revoked token is indistinguishable from one that never
 * existed (docs/18-SALES.md §§20, 28).
 *
 * The token in the URL is the plaintext secret; the table holds only its
 * SHA-256 digest, so the lookup hashes what was presented and matches that.
 *
 * ## Not gated on `pos`
 *
 * An invoice a center has already handed to a customer stays readable if the
 * center later loses the POS entitlement. Withdrawal stops NEW sales; it does
 * not take a customer's receipt back out of their pocket — the downgrade rule
 * Booking follows for appointments (docs/05-ENTITLEMENTS.md §6.2). Platform
 * lifecycle controls are unchanged and sit upstream: `ResolvePublicTenant`
 * answers every public page of a center it will not resolve — provisioning,
 * failed, archived — with the same 404, invoices included.
 */
final class PublicInvoice
{
    public function __construct(private readonly InvoiceRenderer $renderer) {}

    /**
     * The invoice a live share secret opens, or null — the same resolution as
     * {@see forToken()}, for a caller outside Sales that needs the document
     * itself (the public payment page). Unknown, malformed and revoked secrets
     * are all null.
     */
    public function invoiceForToken(#[\SensitiveParameter] string $token): ?Invoice
    {
        if (! InvoiceShareToken::isWellFormed($token)) {
            return null;
        }

        /** @var InvoiceShareLink|null $link */
        $link = InvoiceShareLink::query()
            ->where('token_hash', InvoiceShareToken::hash($token))
            ->whereNotNull('active_invoice_id')
            ->first();

        if (! $link instanceof InvoiceShareLink) {
            return null;
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->whereKey($link->invoice_id)->first();

        return $invoice;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forToken(#[\SensitiveParameter] string $token, ?string $locale = null): ?array
    {
        if (! InvoiceShareToken::isWellFormed($token)) {
            return null;
        }

        // By DIGEST. The secret is never stored, so there is nothing to compare
        // it against but its hash; revoked links have no `active_invoice_id`.
        /** @var InvoiceShareLink|null $link */
        $link = InvoiceShareLink::query()
            ->where('token_hash', InvoiceShareToken::hash($token))
            ->whereNotNull('active_invoice_id')
            ->first();

        if (! $link instanceof InvoiceShareLink) {
            return null;
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->whereKey($link->invoice_id)->with('items')->first();

        if (! $invoice instanceof Invoice) {
            return null;
        }

        /** @var Sale|null $sale */
        $sale = Sale::query()->whereKey($invoice->sale_id)->first(['id', 'status', 'voided_at']);

        if (! $sale instanceof Sale) {
            return $this->renderer->document($invoice, $locale, SaleStatus::Finalized);
        }

        return $this->renderer->document($invoice, $locale, $sale->status, $sale->voided_at?->toImmutable());
    }
}
