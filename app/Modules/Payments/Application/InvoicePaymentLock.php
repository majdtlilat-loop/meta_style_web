<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The serialisation point for everything that changes what an invoice can still
 * collect.
 *
 * ## Why two rows, and in this order
 *
 *   SELECT ... FROM sales    WHERE id = ? FOR UPDATE
 *   SELECT ... FROM invoices WHERE id = ? FOR UPDATE
 *
 * The SALE first, because `CloseSale::void` locks the sale and then asks the
 * payments void guard, which locks the invoice. Taking them in the same order
 * everywhere is what makes a void and a collection on the same bill wait for
 * each other instead of deadlocking. The invoice row is locked, never written:
 * locking an immutable document for concurrency does not edit it
 * (docs/19-PAYMENTS.md §§10–11).
 *
 * Throws outside a transaction, like every lock in Meta Style: a row lock that
 * ends with its own statement protects nothing.
 */
final class InvoicePaymentLock
{
    /**
     * @return array{0: Sale, 1: Invoice}
     *
     * @throws PaymentFailed
     */
    public function lock(Invoice $invoice): array
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('InvoicePaymentLock::lock() must run inside a transaction.');
        }

        /** @var Sale|null $sale */
        $sale = Sale::query()->whereKey($invoice->sale_id)->lockForUpdate()->first();

        /** @var Invoice|null $locked */
        $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

        if (! $sale instanceof Sale || ! $locked instanceof Invoice) {
            throw PaymentFailed::policy('That invoice no longer exists.');
        }

        return [$sale, $locked];
    }

    /**
     * Locks only the invoice, for a caller that already holds the sale lock —
     * the void guard, called from inside `CloseSale::void`.
     */
    public function lockInvoiceForSale(Sale $lockedSale): ?Invoice
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('InvoicePaymentLock::lockInvoiceForSale() must run inside a transaction.');
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->where('sale_id', $lockedSale->getKey())->lockForUpdate()->first();

        return $invoice;
    }
}
