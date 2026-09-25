<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

/**
 * A presented payment or refund, ready to print: labels, tone and the local
 * time of the branch it happened at. Shared by the invoice money panel and
 * the receipts list so both say the same thing about the same payment.
 *
 * Presentation only — every amount is the presenter's own formatted figure.
 */
final class PaymentRows
{
    /**
     * @param  array<string, mixed>  $payment  PaymentsPresenter::payment()
     * @return array<string, mixed>
     */
    public static function payment(array $payment, string $timezone): array
    {
        $payment['method_label'] = MoneyLabels::method($payment['method']);
        $payment['status_label'] = MoneyLabels::paymentStatus($payment['status']);
        $payment['tone'] = MoneyLabels::tone($payment['status']);
        $payment['when'] = BranchTime::label($payment['initiated_at'], $timezone);
        $payment['expires_label'] = ($payment['expires_at'] ?? null) === null ? null : BranchTime::label($payment['expires_at'], $timezone);
        $payment['pending'] = $payment['status'] === 'pending';
        $payment['can_refund'] = $payment['status'] === 'succeeded' && ($payment['refundable']['amount'] ?? 0) > 0;
        $payment['refunded'] = false;

        /** @var list<array<string, mixed>> $refunds */
        $refunds = $payment['refunds'] ?? [];
        $payment['refunds'] = [];

        foreach ($refunds as $refund) {
            $refund = self::refund($refund, $timezone);
            $payment['refunded'] = $payment['refunded'] || $refund['status'] === 'succeeded';
            $payment['refunds'][] = $refund;
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $refund  PaymentsPresenter::refund()
     * @return array<string, mixed>
     */
    public static function refund(array $refund, string $timezone): array
    {
        return $refund + [
            'method_label' => MoneyLabels::method($refund['method']),
            'status_label' => MoneyLabels::refundStatus($refund['status']),
            'tone' => MoneyLabels::tone($refund['status']),
            'when' => BranchTime::label($refund['requested_at'], $timezone),
        ];
    }
}
