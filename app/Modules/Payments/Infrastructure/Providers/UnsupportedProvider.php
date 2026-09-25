<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Domain\Data\GatewayCredentials;
use App\Modules\Payments\Domain\Data\InboundWebhook;
use App\Modules\Payments\Domain\Data\ProviderCapabilities;
use App\Modules\Payments\Domain\Data\ProviderNotification;
use App\Modules\Payments\Domain\Data\ProviderPaymentCreated;
use App\Modules\Payments\Domain\Data\ProviderPaymentRequest;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Data\ProviderRefundResult;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\WebhookRejected;

/**
 * A provider Meta Style intends to support and has NOT implemented.
 *
 * ZainCash, Qi and FastPay each publish documentation, but no adapter has been
 * written against a verified contract and proven with a sandbox transaction. A
 * made-up integration that looks like it works is worse than an honest "not
 * available": it would take a center's money down a path nobody has tested. So
 * the provider is listed — the center can see it is planned — and every
 * operation refuses (docs/19-PAYMENTS.md §16).
 */
final class UnsupportedProvider implements PaymentProvider
{
    public function __construct(
        private readonly string $code,
        private readonly string $displayName,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function capabilities(): ProviderCapabilities
    {
        return ProviderCapabilities::unavailable();
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function createPayment(GatewayCredentials $credentials, ProviderPaymentRequest $request): ProviderPaymentCreated
    {
        throw $this->unsupported();
    }

    public function readNotification(GatewayCredentials $credentials, InboundWebhook $webhook): ProviderNotification
    {
        throw WebhookRejected::because('provider.unsupported');
    }

    public function queryPayment(GatewayCredentials $credentials, string $providerReference): ProviderPaymentStatus
    {
        throw $this->unsupported();
    }

    public function cancelPayment(GatewayCredentials $credentials, string $providerReference): void
    {
        throw $this->unsupported();
    }

    public function refund(GatewayCredentials $credentials, string $providerReference, int $amountMinor, string $currency): ProviderRefundResult
    {
        throw $this->unsupported();
    }

    private function unsupported(): PaymentFailed
    {
        return PaymentFailed::unsupported($this->displayName.' is not available yet.');
    }
}
