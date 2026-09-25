<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Money\Currency;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Domain\Data\GatewayCredentials;
use App\Modules\Payments\Domain\Data\InboundWebhook;
use App\Modules\Payments\Domain\Data\ProviderCapabilities;
use App\Modules\Payments\Domain\Data\ProviderNotification;
use App\Modules\Payments\Domain\Data\ProviderPaymentCreated;
use App\Modules\Payments\Domain\Data\ProviderPaymentRequest;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Data\ProviderRefundResult;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Exceptions\WebhookRejected;
use Carbon\CarbonImmutable;

/**
 * A payment provider that exists only in the test suite.
 *
 * NOT an application provider and never registered outside tests. It exercises
 * the parts of the architecture no verified Iraqi adapter reaches yet: SIGNED
 * callbacks (HMAC-SHA256 of the body with the account's `webhook_secret`, in
 * `X-Test-Signature`) and provider REFUNDS. FIB's own adapter is covered by
 * contract tests against its documented examples instead.
 *
 * Scriptable: tests set what the next create, status query or refund returns,
 * and read back what was called.
 */
final class FakeGatewayProvider implements PaymentProvider
{
    public const CODE = 'testpay';

    public bool $refunds = true;

    public bool $cancellation = true;

    public bool $failCreate = false;

    public bool $failStatus = false;

    /** @var array<string, ProviderPaymentStatus> reference => status */
    public array $statuses = [];

    public ?bool $refundSucceeds = true;

    public bool $refundUnreachable = false;

    /** @var list<string> */
    public array $calls = [];

    private int $sequence = 0;

    public function code(): string
    {
        return self::CODE;
    }

    public function displayName(): string
    {
        return 'TestPay';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            available: true,
            cancellation: $this->cancellation,
            refunds: $this->refunds,
            callbackRequiresStatusQuery: false,
            environments: [GatewayEnvironment::Sandbox, GatewayEnvironment::Live],
            currencies: [Currency::IQD],
        );
    }

    public function credentialFields(): array
    {
        return ['client_id', 'client_secret', 'webhook_secret'];
    }

    public function createPayment(GatewayCredentials $credentials, ProviderPaymentRequest $request): ProviderPaymentCreated
    {
        $this->calls[] = 'create';

        if ($this->failCreate) {
            throw ProviderRequestFailed::because('testpay.unreachable');
        }

        $reference = 'tp-'.(++$this->sequence).'-'.substr($request->merchantReference, 0, 8);

        return new ProviderPaymentCreated($reference, 'CODE'.$this->sequence, 'https://pay.test/'.$reference, CarbonImmutable::now()->addMinutes(15));
    }

    public function readNotification(GatewayCredentials $credentials, InboundWebhook $webhook): ProviderNotification
    {
        $this->calls[] = 'notification';

        $expected = hash_hmac('sha256', $webhook->body, $credentials->get('webhook_secret'));

        if (! hash_equals($expected, (string) $webhook->header('X-Test-Signature'))) {
            throw WebhookRejected::because('testpay.bad_signature');
        }

        /** @var array{reference?: string, event?: string, state?: string, amount?: int, currency?: string} $payload */
        $payload = json_decode($webhook->body, true) ?: [];

        if (! isset($payload['reference'], $payload['state'])) {
            throw WebhookRejected::because('testpay.malformed');
        }

        return new ProviderNotification(
            providerReference: $payload['reference'],
            status: new ProviderPaymentStatus(
                $payload['reference'],
                ProviderState::from($payload['state']),
                $payload['amount'] ?? null,
                $payload['currency'] ?? null,
                $payload['state'] === 'declined' ? 'provider_declined' : null,
            ),
            signatureVerified: true,
            providerEventId: $payload['event'] ?? null,
        );
    }

    public function queryPayment(GatewayCredentials $credentials, string $providerReference): ProviderPaymentStatus
    {
        $this->calls[] = 'query';

        if ($this->failStatus) {
            throw ProviderRequestFailed::because('testpay.unreachable');
        }

        return $this->statuses[$providerReference] ?? new ProviderPaymentStatus($providerReference, ProviderState::Unpaid, null, null);
    }

    public function cancelPayment(GatewayCredentials $credentials, string $providerReference): void
    {
        $this->calls[] = 'cancel';

        if (! $this->cancellation) {
            throw PaymentFailed::unsupported('TestPay cannot cancel.');
        }
    }

    public function refund(GatewayCredentials $credentials, string $providerReference, int $amountMinor, string $currency): ProviderRefundResult
    {
        $this->calls[] = 'refund';

        if (! $this->refunds) {
            throw PaymentFailed::unsupported('TestPay cannot refund.');
        }

        if ($this->refundUnreachable) {
            throw ProviderRequestFailed::because('testpay.unreachable');
        }

        return $this->refundSucceeds
            ? new ProviderRefundResult(true, 'rf-'.$providerReference)
            : new ProviderRefundResult(false, null, 'provider_declined');
    }

    /**
     * A signed callback body and its headers, as TestPay would send it.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function callback(string $reference, string $state, ?int $amount, ?string $currency = 'IQD', string $secret = 'whsec-test-0001', ?string $event = null): array
    {
        $body = (string) json_encode(array_filter([
            'reference' => $reference,
            'state' => $state,
            'amount' => $amount,
            'currency' => $currency,
            'event' => $event,
        ], static fn (mixed $value): bool => $value !== null));

        return [$body, ['X-Test-Signature' => hash_hmac('sha256', $body, $secret), 'Content-Type' => 'application/json']];
    }
}
