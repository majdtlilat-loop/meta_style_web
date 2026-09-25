<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

/**
 * Money went back to a customer: a refund reached `succeeded`.
 *
 * Synchronous and in-transaction, like {@see PaymentSucceeded}: the ledger debit
 * commits with the refund or not at all.
 */
final readonly class RefundSucceeded
{
    public function __construct(
        public int $refundId,
    ) {}
}
