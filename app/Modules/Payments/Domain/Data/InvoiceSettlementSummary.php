<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

use App\Modules\Payments\Domain\Enums\SettlementState;

/**
 * Where an invoice stands with money. The single formula, computed once, by
 * `InvoiceSettlement` (docs/19-PAYMENTS.md §28).
 *
 *   succeeded            money received, gross
 *   pending              gateway payments still possibly on their way
 *   available            invoice − succeeded − pending, never below zero,
 *                        and zero for a voided sale
 *   refunded             money returned (succeeded refunds)
 *   net_collected        succeeded − refunded
 *   state                unpaid | partial | paid — from GROSS succeeded
 *
 * `state` is deliberately blind to refunds. A refund is money leaving the
 * center; it is not the customer owing the amount again, so a paid invoice with
 * a refund is still paid, and its net collected is lower (§9).
 */
final readonly class InvoiceSettlementSummary
{
    public function __construct(
        public int $invoiceTotalMinor,
        public int $succeededMinor,
        public int $pendingMinor,
        public int $availableCollectibleMinor,
        public int $refundedMinor,
        public int $pendingRefundMinor,
        public int $netCollectedMinor,
        public SettlementState $state,
        public bool $voided,
        public string $currency,
    ) {}

    public static function of(int $total, int $succeeded, int $pending, int $refunded, int $pendingRefund, bool $voided, string $currency): self
    {
        $available = $voided ? 0 : max(0, $total - $succeeded - $pending);

        $state = match (true) {
            $succeeded <= 0 => SettlementState::Unpaid,
            $succeeded < $total => SettlementState::Partial,
            default => SettlementState::Paid,
        };

        return new self(
            invoiceTotalMinor: $total,
            succeededMinor: $succeeded,
            pendingMinor: $pending,
            availableCollectibleMinor: $available,
            refundedMinor: $refunded,
            pendingRefundMinor: $pendingRefund,
            netCollectedMinor: $succeeded - $refunded,
            state: $state,
            voided: $voided,
            currency: $currency,
        );
    }
}
