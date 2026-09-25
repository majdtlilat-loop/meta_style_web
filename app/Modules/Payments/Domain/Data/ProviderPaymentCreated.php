<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

use Carbon\CarbonImmutable;

/**
 * The provider opened a payment. Everything here is safe to keep: identifiers
 * and links the provider meant the payer to see — never a token or a secret.
 */
final readonly class ProviderPaymentCreated
{
    public function __construct(
        public string $providerReference,
        public ?string $displayCode,
        public ?string $checkoutUrl,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
