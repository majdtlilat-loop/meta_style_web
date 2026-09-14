<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

/**
 * The life of a sale. Three states, and none of them is about money moving.
 *
 *     draft ──finalize──▶ finalized ──void──▶ voided
 *
 * A DRAFT is the cart: mutable, unnumbered, not yet a financial record.
 * FINALIZED means an invoice was published and the sale is frozen.
 * VOIDED means a finalized sale was cancelled; its invoice still exists.
 *
 * "Unpaid", "partially paid" and "settled" are deliberately absent. They belong
 * to Payment in Phase 10, and a sale that tracked them would become a second,
 * disagreeing record of money (docs/18-SALES.md §5).
 *
 * Discarding a draft is not a state: a draft never became financial, so it is
 * deleted and the audit log keeps the fact.
 */
enum SaleStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Voided = 'voided';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
