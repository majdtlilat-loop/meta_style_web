<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

use App\Kernel\Money\Currency;

/**
 * What an adapter needs to open a payment at its provider. Nothing about the
 * customer: no name, no phone — the provider collects what it needs itself.
 */
final readonly class ProviderPaymentRequest
{
    public function __construct(
        /** Our payment uuid, for providers that accept a merchant reference. */
        public string $merchantReference,
        public int $amountMinor,
        public Currency $currency,
        /** Customer-visible, so the invoice number and nothing private. */
        public string $description,
        /** Where the provider sends its status callback for this account. */
        public string $callbackUrl,
    ) {}
}
