<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * Something a higher module knows that makes publishing this sale wrong.
 *
 * Runs inside `FinalizeSale`, with the sale row LOCKED, before the invoice is
 * issued. Throw to refuse; return to allow. A membership sold with no customer
 * to give it to is the example (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §11).
 *
 * Implementations are tagged `SaleFinalizationGuards::TAG`. Sales never learns
 * who they are — the same neutral seam as `SaleVoidGuard`.
 */
interface SaleFinalizationGuard
{
    /**
     * @throws SaleFailed
     */
    public function assertFinalizable(Sale $locked): void;
}
