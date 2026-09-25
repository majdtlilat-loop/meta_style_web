<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

use App\Modules\Payments\Domain\Enums\ProviderState;

/**
 * What the PROVIDER says about a payment, from a verified source — a signed
 * callback or an authenticated status query. Never a browser redirect.
 *
 * `amountMinor` and `currency` are what the provider reports it received; the
 * settlement Action compares them with the payment before believing `state`
 * (docs/19-PAYMENTS.md §82). They are null only when a provider reports a state
 * that carries no amount, and then a PAID state is refused.
 */
final readonly class ProviderPaymentStatus
{
    public function __construct(
        public string $providerReference,
        public ProviderState $state,
        public ?int $amountMinor,
        public ?string $currency,
        /** A safe code from the adapter's fixed list, for failed/cancelled. */
        public ?string $failureCode = null,
    ) {}
}
