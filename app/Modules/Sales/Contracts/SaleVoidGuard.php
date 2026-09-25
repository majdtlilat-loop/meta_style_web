<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * A module that must agree before a finalized sale is voided.
 *
 * Sales cannot know what else has happened to a sale's invoice — that is the
 * point of the boundary: Sales must never import Payments. But voiding a sale
 * whose invoice has collected money, or has an online payment still in flight,
 * would leave the books contradicting themselves. So the dependency is
 * inverted: Sales defines this seam, a higher module implements it, and
 * `CloseSale::void` asks every registered guard before it changes anything
 * (docs/18-SALES.md §20, docs/19-PAYMENTS.md §18).
 *
 * Called INSIDE the void transaction, with the sale row already locked, so a
 * guard's answer cannot go stale before the void commits. A guard that takes
 * further locks must take them after the sale's — the order every payment path
 * already uses — or two desks deadlock.
 *
 * A guard refuses by throwing; it never mutates anything. No automatic refund,
 * no automatic cancellation: those are explicit operations of their own.
 */
interface SaleVoidGuard
{
    /**
     * @throws SaleFailed when the sale must not be voided yet
     */
    public function assertVoidable(Sale $locked): void;
}
