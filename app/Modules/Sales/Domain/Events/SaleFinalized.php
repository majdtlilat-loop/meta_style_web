<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

/**
 * A sale was finalized and its invoice issued.
 *
 * Dispatched synchronously INSIDE the finalization transaction, so a listener's
 * writes commit with the invoice or not at all (the ADR-053 pattern). Carries
 * identifiers only. A higher module — a membership sold for nothing, say —
 * reacts to it; Sales never learns who listened
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 */
final readonly class SaleFinalized
{
    public function __construct(public int $saleId) {}
}
