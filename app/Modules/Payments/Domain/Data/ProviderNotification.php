<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Data;

/**
 * An inbound callback, after the adapter has read it.
 *
 * `status` is set only when the adapter VERIFIED the callback cryptographically.
 * For a provider whose callbacks are unsigned it is null, and the reference is
 * only a pointer: the Action then asks the provider what is true.
 */
final readonly class ProviderNotification
{
    public function __construct(
        public string $providerReference,
        public ?ProviderPaymentStatus $status,
        public bool $signatureVerified,
        public ?string $providerEventId = null,
    ) {}
}
