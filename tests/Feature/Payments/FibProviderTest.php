<?php

declare(strict_types=1);

use App\Kernel\Money\Currency;
use App\Modules\Payments\Domain\Data\GatewayCredentials;
use App\Modules\Payments\Domain\Data\InboundWebhook;
use App\Modules\Payments\Domain\Data\ProviderPaymentRequest;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Exceptions\ProviderRequestFailed;
use App\Modules\Payments\Domain\Exceptions\WebhookRejected;
use App\Modules\Payments\Infrastructure\Providers\FibProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The FIB adapter, against FIB's documented contract
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §16. Source: https://fib.iq/integrations/web-payments/ and
| FIB's own PHP SDK, read 2026-09-14. The payloads below are the documented
| examples.
|
| CONTRACT TESTS, NOT A SANDBOX RUN. They pin exactly what this adapter sends and
| how it reads the documented answers. No FIB sandbox transaction has been run.
|
*/

function fibCredentials(): GatewayCredentials
{
    return new GatewayCredentials('acc-uuid', GatewayEnvironment::Sandbox, ['client_id' => 'fib-client-001', 'client_secret' => 'fib-secret-VALUE-42']);
}

function fibToken(): array
{
    return ['access_token' => 'eyJ-token-value', 'expires_in' => 60, 'token_type' => 'Bearer'];
}

it('creates a payment exactly as FIB documents it', function (): void {
    Http::fake([
        'fib.stage.fib.iq/auth/*' => Http::response(fibToken(), 200),
        'fib.stage.fib.iq/protected/v1/payments' => Http::response([
            'paymentId' => '4d6f7625-60f7-48e3-82e3-b4592a4eb993',
            'readableCode' => 'IK8HA3W7',
            'qrCode' => 'data:image/png;base64,iVBORw0KGgo=',
            'validUntil' => '2022-01-31T12:26:12.544Z',
            'personalAppLink' => 'https://personal.fib.iq/pay/4d6f7625',
            'businessAppLink' => 'https://business.fib.iq/pay/4d6f7625',
            'corporateAppLink' => 'https://corporate.fib.iq/pay/4d6f7625',
        ], 202),
    ]);

    $created = app(FibProvider::class)->createPayment(fibCredentials(), new ProviderPaymentRequest(
        merchantReference: 'payment-uuid',
        amountMinor: 500,
        currency: Currency::IQD,
        description: 'Invoice INV-2026-000017 — a description long enough to be cut',
        callbackUrl: 'https://center.test/api/v1/payments/key/gateways/acc/webhook',
    ));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token'
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === 'fib-client-001'
        && $request['client_secret'] === 'fib-secret-VALUE-42');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fib.stage.fib.iq/protected/v1/payments'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer eyJ-token-value')
        // "500.00" for 500 IQD — the documented format, never a float.
        && $request['monetaryValue'] === ['amount' => '500.00', 'currency' => 'IQD']
        && $request['statusCallbackUrl'] === 'https://center.test/api/v1/payments/key/gateways/acc/webhook'
        && mb_strlen((string) $request['description']) <= 50);

    expect($created->providerReference)->toBe('4d6f7625-60f7-48e3-82e3-b4592a4eb993')
        ->and($created->displayCode)->toBe('IK8HA3W7')
        ->and($created->checkoutUrl)->toBe('https://personal.fib.iq/pay/4d6f7625')
        ->and($created->expiresAt?->toIso8601String())->toBe('2022-01-31T12:26:12+00:00');
});

it('reads the documented statuses, and refuses to invent an amount it cannot read exactly', function (): void {
    $status = fn (array $body): array => ['paymentId' => 'pay-1', 'validUntil' => '2022-01-31T12:26:12.544Z', 'paidAt' => null, 'declinedAt' => null, 'paidBy' => null, ...$body];

    Http::fakeSequence('fib.stage.fib.iq/auth/*')->push(fibToken())->push(fibToken())->push(fibToken())->push(fibToken())->push(fibToken());
    Http::fakeSequence('fib.stage.fib.iq/protected/v1/payments/pay-1/status')
        ->push($status(['status' => 'PAID', 'amount' => ['amount' => 500, 'currency' => 'IQD'], 'decliningReason' => null]))
        ->push($status(['status' => 'UNPAID', 'amount' => ['amount' => 500, 'currency' => 'IQD'], 'decliningReason' => null]))
        ->push($status(['status' => 'DECLINED', 'amount' => ['amount' => 500, 'currency' => 'IQD'], 'decliningReason' => 'PAYMENT_EXPIRATION']))
        ->push($status(['status' => 'DECLINED', 'amount' => ['amount' => 500, 'currency' => 'IQD'], 'decliningReason' => 'PAYMENT_CANCELLATION']))
        ->push($status(['status' => 'PAID', 'amount' => ['amount' => 500.5, 'currency' => 'IQD'], 'decliningReason' => null]));

    $fib = app(FibProvider::class);

    $paid = $fib->queryPayment(fibCredentials(), 'pay-1');
    $unpaid = $fib->queryPayment(fibCredentials(), 'pay-1');
    $expired = $fib->queryPayment(fibCredentials(), 'pay-1');
    $cancelled = $fib->queryPayment(fibCredentials(), 'pay-1');
    $fractional = $fib->queryPayment(fibCredentials(), 'pay-1');

    expect($paid->state)->toBe(ProviderState::Paid)
        ->and($paid->amountMinor)->toBe(500)
        ->and($paid->currency)->toBe('IQD')
        ->and($unpaid->state)->toBe(ProviderState::Unpaid)
        ->and($expired->state)->toBe(ProviderState::Declined)
        ->and($expired->failureCode)->toBe('provider_expired')
        ->and($cancelled->state)->toBe(ProviderState::Cancelled)
        // 500.5 IQD is not an IQD amount: reported as unknown, so it cannot settle.
        ->and($fractional->state)->toBe(ProviderState::Paid)
        ->and($fractional->amountMinor)->toBeNull();
});

it('treats a FIB callback as a pointer only — its claimed status is never believed', function (): void {
    $notification = app(FibProvider::class)->readNotification(
        fibCredentials(),
        new InboundWebhook('{"id":"4d6f7625-60f7-48e3-82e3-b4592a4eb993","status":"PAID"}', ['content-type' => 'application/json']),
    );

    expect($notification->providerReference)->toBe('4d6f7625-60f7-48e3-82e3-b4592a4eb993')
        ->and($notification->status)->toBeNull()
        ->and($notification->signatureVerified)->toBeFalse()
        ->and(app(FibProvider::class)->capabilities()->callbackRequiresStatusQuery)->toBeTrue();

    expect(fn () => app(FibProvider::class)->readNotification(fibCredentials(), new InboundWebhook('{"status":"PAID"}', [])))
        ->toThrow(WebhookRejected::class);

    expect(fn () => app(FibProvider::class)->readNotification(fibCredentials(), new InboundWebhook('{"id":"../../admin","status":"PAID"}', [])))
        ->toThrow(WebhookRejected::class);
});

it('cancels through the documented endpoint, and refuses refunds the documentation does not offer', function (): void {
    Http::fake([
        'fib.stage.fib.iq/auth/*' => Http::response(fibToken(), 200),
        'fib.stage.fib.iq/protected/v1/payments/pay-1/cancel' => Http::response(null, 204),
    ]);

    app(FibProvider::class)->cancelPayment(fibCredentials(), 'pay-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fib.stage.fib.iq/protected/v1/payments/pay-1/cancel' && $request->method() === 'POST');

    expect(app(FibProvider::class)->capabilities()->refunds)->toBeFalse();

    expect(fn () => app(FibProvider::class)->refund(fibCredentials(), 'pay-1', 500, 'IQD'))
        ->toThrow(PaymentFailed::class, 'no refund API');
});

it('fails safely when FIB is unreachable or refuses the token, and logs nothing secret', function (): void {
    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    Http::fake(['fib.stage.fib.iq/auth/*' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(fn () => app(FibProvider::class)->queryPayment(fibCredentials(), 'pay-1'))
        ->toThrow(ProviderRequestFailed::class, 'fib.token_failed');

    foreach ($lines as $line) {
        expect(str_contains($line, 'fib-secret-VALUE-42'))->toBeFalse();
    }

    // No live host configured on this installation: live is simply unavailable.
    config(['payments.providers.fib.base_urls.live' => null]);

    expect(app(FibProvider::class)->capabilities()->environments)->toBe([GatewayEnvironment::Sandbox]);

    expect(fn () => app(FibProvider::class)->queryPayment(
        new GatewayCredentials('acc', GatewayEnvironment::Live, ['client_id' => 'a', 'client_secret' => 'b']),
        'pay-1',
    ))->toThrow(PaymentFailed::class, 'not available');
});
