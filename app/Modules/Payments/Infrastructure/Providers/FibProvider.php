<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

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
use App\Modules\Payments\Domain\ProviderAmount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * First Iraqi Bank — Online Payments.
 *
 * ## The contract this implements, and where it comes from
 *
 * Written against FIB's own documentation, read 2026-09-14:
 *
 *   https://fib.iq/integrations/web-payments/          (examples dated 2022-01)
 *   https://github.com/First-Iraqi-Bank/fib-php-payment-sdk   (FIB's own SDK)
 *
 * The two agree on everything used here:
 *
 *   POST {base}/auth/realms/fib-online-shop/protocol/openid-connect/token
 *        grant_type=client_credentials, client_id, client_secret (form-encoded)
 *   POST {base}/protected/v1/payments
 *        {"monetaryValue":{"amount":"500.00","currency":"IQD"},
 *         "statusCallbackUrl":"...","description":"..."}          → paymentId,
 *        readableCode, personalAppLink, validUntil
 *   GET  {base}/protected/v1/payments/{paymentId}/status
 *        → status PAID | UNPAID | DECLINED, amount {amount, currency},
 *          decliningReason SERVER_FAILURE | PAYMENT_EXPIRATION | PAYMENT_CANCELLATION
 *   POST {base}/protected/v1/payments/{paymentId}/cancel          → 204
 *
 * ## What it deliberately does not do
 *
 *  - REFUND. The official page documents no refund operation (FIB's SDK calls
 *    one, but an undocumented endpoint is not a contract). `refunds: false`.
 *  - TRUST A CALLBACK. FIB's callback carries `id` and `status` and nothing that
 *    proves FIB sent it. So the callback is only a pointer: the status is
 *    always read back from FIB with the center's own credentials, and only that
 *    answer — amount and currency checked — can settle a payment.
 *  - GUESS PRODUCTION. Only the stage host is published; FIB issues production
 *    access on approval. The live host is an operator setting.
 *
 * NOT YET PROVEN: no sandbox transaction has been run against this adapter.
 * Everything it sends and reads is pinned by contract tests built from the
 * documented examples (docs/19-PAYMENTS.md §16).
 *
 * Never logs a body, a header, a token or a credential.
 */
final class FibProvider implements PaymentProvider
{
    public const CODE = 'fib';

    private const TOKEN_PATH = '/auth/realms/fib-online-shop/protocol/openid-connect/token';

    private const PAYMENTS_PATH = '/protected/v1/payments';

    /** FIB's documented limit on `description`. */
    private const DESCRIPTION_MAX = 50;

    public function __construct(private readonly Config $config) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function displayName(): string
    {
        return 'FIB';
    }

    public function capabilities(): ProviderCapabilities
    {
        $environments = array_values(array_filter(
            GatewayEnvironment::cases(),
            fn (GatewayEnvironment $environment): bool => $this->baseUrl($environment) !== null,
        ));

        return new ProviderCapabilities(
            available: true,
            cancellation: true,
            refunds: false,
            callbackRequiresStatusQuery: true,
            environments: $environments,
            currencies: [Currency::IQD],
        );
    }

    public function credentialFields(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function createPayment(GatewayCredentials $credentials, ProviderPaymentRequest $request): ProviderPaymentCreated
    {
        if ($request->currency !== Currency::IQD) {
            throw PaymentFailed::unsupported('FIB accepts payments in IQD only.');
        }

        $response = $this->send(
            fn (PendingRequest $http, string $base) => $http->post($base.self::PAYMENTS_PATH, [
                'monetaryValue' => [
                    'amount' => ProviderAmount::toDecimalString($request->amountMinor, $request->currency),
                    'currency' => $request->currency->value,
                ],
                'statusCallbackUrl' => $request->callbackUrl,
                'description' => mb_substr($request->description, 0, self::DESCRIPTION_MAX),
            ]),
            $credentials,
            'fib.create_failed',
        );

        $reference = $response->json('paymentId');

        if (! is_string($reference) || ! self::isReference($reference)) {
            throw ProviderRequestFailed::because('fib.create_unreadable');
        }

        $code = $response->json('readableCode');
        $link = $response->json('personalAppLink');

        return new ProviderPaymentCreated(
            providerReference: $reference,
            displayCode: is_string($code) ? mb_substr($code, 0, 64) : null,
            checkoutUrl: is_string($link) && str_starts_with($link, 'https://') ? mb_substr($link, 0, 1000) : null,
            expiresAt: $this->instant($response->json('validUntil')),
        );
    }

    public function readNotification(GatewayCredentials $credentials, InboundWebhook $webhook): ProviderNotification
    {
        try {
            $payload = json_decode($webhook->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw WebhookRejected::because('fib.callback_unreadable');
        }

        $reference = is_array($payload) ? ($payload['id'] ?? null) : null;

        if (! is_string($reference) || ! self::isReference($reference)) {
            throw WebhookRejected::because('fib.callback_without_reference');
        }

        // Unsigned: the reference is all this callback is worth. Its `status`
        // is ignored on purpose — the Action asks FIB.
        return new ProviderNotification(providerReference: $reference, status: null, signatureVerified: false);
    }

    public function queryPayment(GatewayCredentials $credentials, string $providerReference): ProviderPaymentStatus
    {
        if (! self::isReference($providerReference)) {
            throw ProviderRequestFailed::because('fib.invalid_reference');
        }

        $response = $this->send(
            fn (PendingRequest $http, string $base) => $http->get($base.self::PAYMENTS_PATH.'/'.rawurlencode($providerReference).'/status'),
            $credentials,
            'fib.status_failed',
        );

        if ($response->json('paymentId') !== $providerReference) {
            throw ProviderRequestFailed::because('fib.status_for_another_payment');
        }

        $state = match ($response->json('status')) {
            'PAID' => ProviderState::Paid,
            'UNPAID' => ProviderState::Unpaid,
            'DECLINED' => $response->json('decliningReason') === 'PAYMENT_CANCELLATION'
                ? ProviderState::Cancelled
                : ProviderState::Declined,
            default => throw ProviderRequestFailed::because('fib.status_unknown'),
        };

        $currency = $response->json('amount.currency');
        $currencyCode = is_string($currency) ? Currency::tryFrom($currency) : null;

        try {
            $amount = $currencyCode === null ? null : ProviderAmount::toMinor($response->json('amount.amount'), $currencyCode);
        } catch (InvalidArgumentException) {
            // Not an exact amount: reported as unknown, so a PAID cannot settle.
            $amount = null;
        }

        $failure = match ($response->json('decliningReason')) {
            'PAYMENT_EXPIRATION' => 'provider_expired',
            'PAYMENT_CANCELLATION' => 'provider_cancelled',
            'SERVER_FAILURE' => 'provider_failure',
            default => null,
        };

        return new ProviderPaymentStatus(
            providerReference: $providerReference,
            state: $state,
            amountMinor: $amount,
            currency: is_string($currency) ? $currency : null,
            failureCode: $failure,
        );
    }

    public function cancelPayment(GatewayCredentials $credentials, string $providerReference): void
    {
        if (! self::isReference($providerReference)) {
            throw ProviderRequestFailed::because('fib.invalid_reference');
        }

        $this->send(
            fn (PendingRequest $http, string $base) => $http->post($base.self::PAYMENTS_PATH.'/'.rawurlencode($providerReference).'/cancel'),
            $credentials,
            'fib.cancel_failed',
        );
    }

    public function refund(GatewayCredentials $credentials, string $providerReference, int $amountMinor, string $currency): ProviderRefundResult
    {
        throw PaymentFailed::unsupported('FIB publishes no refund API. Refund this payment in cash or by a manual transfer.');
    }

    /**
     * Fetches a token and performs one authenticated call. The token is used
     * once and dropped: FIB documents it as valid for 60 seconds.
     *
     * @param  callable(PendingRequest, string): Response  $call
     */
    private function send(callable $call, GatewayCredentials $credentials, string $failureCode): Response
    {
        $base = $this->baseUrl($credentials->environment);

        if ($base === null) {
            throw PaymentFailed::unsupported('FIB is not available in that environment on this installation.');
        }

        try {
            $token = $this->http()->asForm()->post($base.self::TOKEN_PATH, [
                'grant_type' => 'client_credentials',
                'client_id' => $credentials->get('client_id'),
                'client_secret' => $credentials->get('client_secret'),
            ]);

            $accessToken = $token->successful() ? $token->json('access_token') : null;

            if (! is_string($accessToken) || $accessToken === '') {
                throw ProviderRequestFailed::because('fib.token_failed');
            }

            $response = $call($this->http()->withToken($accessToken)->acceptJson(), $base);
        } catch (ConnectionException) {
            throw ProviderRequestFailed::because('fib.unreachable');
        }

        if (! $response->successful()) {
            throw ProviderRequestFailed::because($failureCode);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) $this->config->get('payments.http.timeout', 10))
            ->connectTimeout((int) $this->config->get('payments.http.connect_timeout', 5));
    }

    /**
     * The platform-configured host for an environment, and only an HTTPS one.
     */
    private function baseUrl(GatewayEnvironment $environment): ?string
    {
        $url = $this->config->get('payments.providers.fib.base_urls.'.$environment->value);

        return is_string($url) && str_starts_with($url, 'https://') ? rtrim($url, '/') : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A reference goes into a URL path, so it is held to a strict shape — FIB
     * issues UUIDs.
     */
    private static function isReference(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9\-]{1,128}$/', $value) === 1;
    }
}
