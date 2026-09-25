<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

/**
 * A provider's synchronous answer to a refund request. A refund is believed
 * only when `succeeded` is true; anything else leaves no ledger debit.
 */
final readonly class ProviderRefundResult
{
    public function __construct(
        public bool $succeeded,
        public ?string $providerRefundReference = null,
        public ?string $failureCode = null,
    ) {}
}
