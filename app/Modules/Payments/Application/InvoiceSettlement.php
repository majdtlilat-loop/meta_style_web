<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Domain\Data\InvoiceSettlementSummary;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * THE settlement formula, for one invoice or a page of them.
 *
 * The till, the sales screen, the public invoice, the API and the finance
 * dashboard all read settlement through here — never a copy of the arithmetic
 * (docs/19-PAYMENTS.md §28).
 *
 * ## Cost
 *
 * Three queries whatever the number of invoices: payments grouped by invoice and
 * status, refunds grouped by invoice and status, and sale statuses. Called under
 * the invoice lock by the collection Actions, so what they check is what
 * commits.
 */
final class InvoiceSettlement
{
    public function forInvoice(Invoice $invoice): InvoiceSettlementSummary
    {
        return $this->forInvoices([$invoice])[$invoice->getKey()];
    }

    /**
     * @param  list<Invoice>  $invoices
     * @return array<int, InvoiceSettlementSummary> keyed by invoice id
     */
    public function forInvoices(array $invoices): array
    {
        if ($invoices === []) {
            return [];
        }

        $ids = array_map(static fn (Invoice $invoice): int => (int) $invoice->getKey(), $invoices);

        $payments = [];

        /** @var list<object{invoice_id: int|string, status: string, total: int|string|null}> $paymentRows */
        $paymentRows = DB::connection('tenant')->table('payments')
            ->whereIn('invoice_id', $ids)
            ->selectRaw('invoice_id, status, SUM(amount_minor) AS total')
            ->groupBy('invoice_id', 'status')
            ->get()
            ->all();

        foreach ($paymentRows as $row) {
            $payments[(int) $row->invoice_id][$row->status] = (int) $row->total;
        }

        $refunds = [];

        /** @var list<object{invoice_id: int|string, status: string, total: int|string|null}> $refundRows */
        $refundRows = DB::connection('tenant')->table('refunds')
            ->join('payments', 'payments.id', '=', 'refunds.payment_id')
            ->whereIn('payments.invoice_id', $ids)
            ->selectRaw('payments.invoice_id AS invoice_id, refunds.status AS status, SUM(refunds.amount_minor) AS total')
            ->groupBy('payments.invoice_id', 'refunds.status')
            ->get()
            ->all();

        foreach ($refundRows as $row) {
            $refunds[(int) $row->invoice_id][$row->status] = (int) $row->total;
        }

        /** @var array<int, string> $saleStatuses */
        $saleStatuses = Sale::query()
            ->whereIn('id', array_map(static fn (Invoice $invoice): int => $invoice->sale_id, $invoices))
            ->pluck('status', 'id')
            ->map(static fn (mixed $status): string => $status instanceof SaleStatus ? $status->value : (string) $status)
            ->all();

        $summaries = [];

        foreach ($invoices as $invoice) {
            $id = (int) $invoice->getKey();

            $summaries[$id] = InvoiceSettlementSummary::of(
                total: $invoice->grand_total_minor,
                succeeded: $payments[$id][PaymentStatus::Succeeded->value] ?? 0,
                pending: $payments[$id][PaymentStatus::Pending->value] ?? 0,
                refunded: $refunds[$id][RefundStatus::Succeeded->value] ?? 0,
                pendingRefund: $refunds[$id][RefundStatus::Pending->value] ?? 0,
                voided: ($saleStatuses[$invoice->sale_id] ?? null) === SaleStatus::Voided->value,
                currency: $invoice->currency,
            );
        }

        return $summaries;
    }

    /**
     * What may still be refunded on one payment, from its LOADED refunds: the
     * amount less every refund that holds money against it (succeeded, or
     * pending at a provider). Never below zero.
     *
     * For display. `RequestRefund` computes the same figure again under the
     * payment's lock, and that is the one that decides (docs/19-PAYMENTS.md §25).
     */
    public static function refundableOf(Payment $payment): int
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return 0;
        }

        $held = 0;

        foreach ($payment->refunds as $refund) {
            if ($refund->status->holdsPaymentAmount()) {
                $held += $refund->amount_minor;
            }
        }

        return max(0, $payment->amount_minor - $held);
    }

    /**
     * The same formula, aggregated: what is still owed on the non-voided
     * invoices a set of branches issued in a window.
     *
     *     outstanding = Σ (invoice total − min(invoice total, succeeded payments))
     *
     * One query, bounded by `invoices(branch_id, issued_at)` and
     * `payments(invoice_id, status)`, so the finance dashboard never loads a
     * quarter's invoices to add them up — and never keeps a second copy of the
     * rule outside this class (docs/19-PAYMENTS.md §28).
     *
     * @param  list<int>  $branchIds
     */
    public function outstandingIssuedBetween(array $branchIds, CarbonInterface $from, CarbonInterface $until): int
    {
        if ($branchIds === []) {
            return 0;
        }

        $paid = DB::connection('tenant')->table('payments')
            ->join('invoices as paid_invoices', 'paid_invoices.id', '=', 'payments.invoice_id')
            ->whereIn('paid_invoices.branch_id', $branchIds)
            ->where('paid_invoices.issued_at', '>=', $from)
            ->where('paid_invoices.issued_at', '<', $until)
            ->where('payments.status', PaymentStatus::Succeeded->value)
            ->groupBy('payments.invoice_id')
            ->selectRaw('payments.invoice_id AS invoice_id, SUM(payments.amount_minor) AS paid');

        // Through the model, never `table('invoices')`: that path is closed to
        // everything but reads the model can see (ADR-054).
        $total = Invoice::query()->toBase()
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->leftJoinSub($paid, 'paid', 'paid.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.branch_id', $branchIds)
            ->where('invoices.issued_at', '>=', $from)
            ->where('invoices.issued_at', '<', $until)
            ->where('sales.status', SaleStatus::Finalized->value)
            ->selectRaw('SUM(invoices.grand_total_minor - LEAST(invoices.grand_total_minor, COALESCE(paid.paid, 0))) AS outstanding')
            ->value('outstanding');

        return (int) $total;
    }
}
