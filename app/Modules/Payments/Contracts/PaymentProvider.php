<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\Domain\Data\GatewayCredentials;
use App\Modules\Payments\Domain\Data\InboundWebhook;
use App\Modules\Payments\Domain\Data\ProviderCapabilities;
use App\Modules\Payments\Domain\Data\ProviderNotification;
use App\Modules\Payments\Domain\Data\ProviderPaymentCreated;
use App\Modules\Payments\Domain\Data\ProviderPaymentRequest;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Data\ProviderRefundResult;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Exceptions\WebhookRejected;

/**
 * One payment provider, translated. No business rules live here — an adapter
 * speaks the provider's contract and reports facts; the Actions decide
 * (docs/14-FUTURE-INTEGRATIONS.md §1, docs/19-PAYMENTS.md §17).
 *
 * ## Only what the provider really does
 *
 * {@see capabilities()} is the truth. An adapter whose provider has no
 * documented refund API throws {@see PaymentFailed::unsupported()} from
 * {@see refund()} and says `refunds: false`; nothing up the stack pretends.
 *
 * ## Where it talks to
 *
 * The provider's base URL comes from platform configuration for the account's
 * environment — never from the account, never from a request. Adapters take no
 * URL parameter precisely so a tenant cannot aim one somewhere else.
 *
 * ## What it must never do
 *
 * Log a request or response body, an Authorization header, a token or a
 * signature; return anything secret in a result object.
 */
interface PaymentProvider
{
    /** The registry code stored on accounts and payments: `fib`, `zaincash`... */
    public function code(): string;

    public function displayName(): string;

    public function capabilities(): ProviderCapabilities;

    /**
     * @return list<string> credential field names a manager must supply
     */
    public function credentialFields(): array;

    /**
     * @throws ProviderRequestFailed when the provider did not answer usably
     * @throws PaymentFailed when unsupported
     */
    public function createPayment(GatewayCredentials $credentials, ProviderPaymentRequest $request): ProviderPaymentCreated;

    /**
     * Reads a callback. Verifies its signature when the provider signs them;
     * otherwise returns only the reference, for a status query.
     *
     * @throws WebhookRejected when the callback is malformed or its signature is wrong
     */
    public function readNotification(GatewayCredentials $credentials, InboundWebhook $webhook): ProviderNotification;

    /**
     * The provider's authoritative status for a payment it issued.
     *
     * @throws ProviderRequestFailed
     * @throws PaymentFailed when unsupported
     */
    public function queryPayment(GatewayCredentials $credentials, string $providerReference): ProviderPaymentStatus;

    /**
     * @throws ProviderRequestFailed
     * @throws PaymentFailed when unsupported
     */
    public function cancelPayment(GatewayCredentials $credentials, string $providerReference): void;

    /**
     * @throws ProviderRequestFailed
     * @throws PaymentFailed when unsupported
     */
    public function refund(GatewayCredentials $credentials, string $providerReference, int $amountMinor, string $currency): ProviderRefundResult;
}
