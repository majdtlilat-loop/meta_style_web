<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

/**
 * Money was received: a payment reached `succeeded`.
 *
 * Dispatched SYNCHRONOUSLY inside the transaction that made the payment
 * succeed, so what listens — Finance recording the collection in the ledger —
 * commits with it or rolls it back. Never queued: a collection reported to the
 * till with no ledger movement behind it is the split brain this prevents
 * (docs/19-PAYMENTS.md §37, ADR-053's pattern).
 *
 * Carries identifiers only.
 */
final readonly class PaymentSucceeded
{
    public function __construct(
        public int $paymentId,
    ) {}
}
