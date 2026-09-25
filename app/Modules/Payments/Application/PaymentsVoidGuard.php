<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Sales\Contracts\SaleVoidGuard;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * Payments' answer to "may this sale be voided?" — only when no money is
 * collected on it and none is on its way.
 *
 *   an online payment is pending     → refuse: resolve it first (cancel it, or
 *                                      let the provider report it)
 *   a refund is pending              → refuse: wait for it
 *   money is collected, net of
 *   refunds                          → refuse: refund it explicitly first
 *   otherwise                        → allowed
 *
 * It never refunds and never cancels on its own. A void that silently kept
 * collected money would leave a voided sale whose customer paid; a void that
 * silently refunded would move money nobody decided to move
 * (docs/19-PAYMENTS.md §27).
 *
 * Runs inside `CloseSale::void`'s transaction with the SALE already locked, and
 * locks the invoice next — the lock order every payment path uses.
 */
final class PaymentsVoidGuard implements SaleVoidGuard
{
    public function __construct(
        private readonly InvoicePaymentLock $lock,
        private readonly InvoiceSettlement $settlement,
    ) {}

    public function assertVoidable(Sale $locked): void
    {
        $invoice = $this->lock->lockInvoiceForSale($locked);

        if ($invoice === null) {
            return;
        }

        $summary = $this->settlement->forInvoice($invoice);

        if ($summary->pendingMinor > 0) {
            throw SaleFailed::policy(
                'This invoice has an online payment in progress. Cancel it, or wait for the provider to report it, before voiding.',
                ['pending_minor' => $summary->pendingMinor],
            );
        }

        if ($summary->pendingRefundMinor > 0) {
            throw SaleFailed::policy('A refund on this invoice is still being processed. Wait for it before voiding.');
        }

        if ($summary->netCollectedMinor > 0) {
            throw SaleFailed::policy(
                'Money has been collected on this invoice. Refund it before voiding the sale.',
                ['net_collected_minor' => $summary->netCollectedMinor],
            );
        }
    }
}
