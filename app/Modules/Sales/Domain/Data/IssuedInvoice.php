<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Data;

use App\Modules\Sales\Domain\Models\Invoice;

/**
 * What finalizing a sale hands back: the invoice, and — only to the call that
 * actually published it — the plaintext secret of its customer link.
 *
 * A repeated finalize (a double click, a retry, the second of two desks) gets
 * the SAME invoice with `replayed: true` and no secret, because the secret was
 * never stored and cannot be recovered. A screen that needs a link at that
 * point issues a new one (docs/18-SALES.md §§18, 20).
 */
final readonly class IssuedInvoice
{
    public function __construct(
        public Invoice $invoice,
        #[\SensitiveParameter]
        public ?string $shareToken,
        public bool $replayed,
    ) {}
}
