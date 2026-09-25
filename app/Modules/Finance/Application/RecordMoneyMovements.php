<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Enums\EntrySource;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Events\RefundSucceeded;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Turns the Payments module's money facts into ledger entries — synchronously,
 * inside the transaction that raised them.
 *
 * Payments does not know Finance exists: it announces "a payment succeeded" and
 * this listener records the collection. Registered with `Event::listen` in the
 * application provider, never queued. If the entry cannot be written the
 * exception rolls the payment back with it (docs/20-FINANCE.md §37 — the
 * ADR-053 pattern).
 *
 * Recorded whatever the center's `finance` entitlement: the ledger is the record
 * of money that moved, not a feature. `finance` gates the dashboard and
 * expenses built on top of it.
 */
final class RecordMoneyMovements
{
    public function __construct(private readonly Ledger $ledger) {}

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        /** @var Payment $payment */
        $payment = Payment::query()->whereKey($event->paymentId)->firstOrFail();

        $number = Invoice::query()->whereKey($payment->invoice_id)->value('number');

        $this->ledger->append(
            kind: EntryKind::Collection,
            source: EntrySource::Payment,
            sourceUuid: $payment->uuid,
            branchId: $payment->branch_id,
            amountMinor: $payment->amount_minor,
            currency: $payment->currency,
            method: $payment->method,
            provider: $payment->provider,
            cashierShiftId: $payment->cashier_shift_id,
            label: 'Payment · '.(is_string($number) ? $number : 'invoice'),
            occurredAt: $payment->succeeded_at ?? Carbon::now()->utc(),
        );
    }

    public function handleRefundSucceeded(RefundSucceeded $event): void
    {
        /** @var Refund $refund */
        $refund = Refund::query()->whereKey($event->refundId)->firstOrFail();

        /** @var Payment $payment */
        $payment = Payment::query()->whereKey($refund->payment_id)->firstOrFail();

        $number = Invoice::query()->whereKey($payment->invoice_id)->value('number');

        $this->ledger->append(
            kind: EntryKind::Refund,
            source: EntrySource::Refund,
            sourceUuid: $refund->uuid,
            branchId: $refund->branch_id,
            amountMinor: $refund->amount_minor,
            currency: $refund->currency,
            method: $refund->method,
            provider: $refund->provider,
            cashierShiftId: $refund->cashier_shift_id,
            label: 'Refund · '.(is_string($number) ? $number : 'invoice'),
            occurredAt: $refund->succeeded_at ?? Carbon::now()->utc(),
        );
    }
}
