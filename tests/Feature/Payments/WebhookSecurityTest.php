<?php

declare(strict_types=1);

use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\ManageGatewayAccount;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGatewayProvider;

/*
|--------------------------------------------------------------------------
| Provider callbacks: believed only when they can be
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§21–24, 67–68, 83–84.
|
| No staff authentication, no entitlement — and nothing settles without a
| verified signature or an authenticated status query. The answers say as little
| as possible, the body is never kept, and a downgrade after a payment started
| does not strand the money.
|
*/

/**
 * @return array{0: Payment, 1: GatewayAccount}
 */
function whPending(): array
{
    test()->grantPayments();
    test()->fakeGateway();

    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();
    $account = test()->gatewayAccount($seed['branch']);
    $invoice = test()->issuedInvoice($seed, $owner);

    return [app(InitiateGatewayPayment::class)->fromDesk($invoice, $account, $owner, 20000), $account];
}

/**
 * A provider callback. Phase 15: `public.tenant` resolves the center from its
 * own host, whose slug the `{center}` segment repeats — the URL
 * `GatewayConnections::callbackUrl()` publishes when a payment starts there.
 */
function whPost(array $center, string $account, string $body, array $headers): TestResponse
{
    return test()->call('POST', 'http://'.$center['registration']->requested_slug.'.localhost:8000/api/v1/payments/'.$center['registration']->requested_slug.'/gateways/'.$account.'/webhook', [], [], [], test()->transformHeadersToServerVars($headers), $body);
}

it('refuses a callback with a wrong or missing signature, and changes nothing', function (): void {
    $center = $this->registerCenter();

    [$payment, $account] = $this->asCenter($center['tenant'], fn (): array => whPending());

    [$body, $headers] = FakeGatewayProvider::callback((string) $payment->provider_payment_reference, 'paid', 20000, secret: 'not-the-secret');

    whPost($center, $account->uuid, $body, $headers)->assertStatus(400)->assertExactJson(['received' => false]);
    whPost($center, $account->uuid, $body, ['Content-Type' => 'application/json'])->assertStatus(400);

    $this->asCenter($center['tenant'], function () use ($payment): void {
        expect($payment->fresh()?->status)->toBe(PaymentStatus::Pending)
            ->and(WebhookEvent::query()->count())->toBe(0)
            ->and(FinanceEntry::query()->count())->toBe(0);
    });
});

it('gives an unknown account nothing, and an unknown payment the same answer as a real one', function (): void {
    $center = $this->registerCenter();

    [$payment, $account] = $this->asCenter($center['tenant'], fn (): array => whPending());

    [$body, $headers] = FakeGatewayProvider::callback('never-issued', 'paid', 20000);

    whPost($center, (string) Str::uuid(), $body, $headers)->assertStatus(400);

    // A properly signed callback for a reference this account never issued:
    // acknowledged exactly like a real one, so references cannot be probed.
    whPost($center, $account->uuid, $body, $headers)->assertStatus(202)->assertExactJson(['received' => true]);

    $this->asCenter($center['tenant'], function () use ($payment): void {
        expect($payment->fresh()?->status)->toBe(PaymentStatus::Pending)
            ->and(FinanceEntry::query()->count())->toBe(0);
    });
});

it('still completes a payment the center started before it lost online payments or disabled the gateway', function (): void {
    $center = $this->registerCenter();

    [$payment, $account] = $this->asCenter($center['tenant'], function (): array {
        [$payment, $account] = whPending();

        // The commercial package changes while the customer is paying...
        $this->revokeEntitlement('payments');
        // ...and a manager switches the account off.
        app(ManageGatewayAccount::class)->setEnabled($account, $this->ownerWithCatalogAccess(), false);

        return [$payment, $account];
    });

    [$body, $headers] = FakeGatewayProvider::callback((string) $payment->provider_payment_reference, 'paid', 20000);

    whPost($center, $account->uuid, $body, $headers)->assertStatus(202);

    $this->asCenter($center['tenant'], function () use ($payment): void {
        // Money already moving does not depend on today's plan.
        expect($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
            ->and(FinanceEntry::query()->where('source_uuid', $payment->uuid)->count())->toBe(1)
            ->and(GatewayAccount::query()->value('credentials'))->not->toBeNull();
    });
});

it('keeps no callback body, and writes no secret, signature or credential to logs, audit or events', function (): void {
    $center = $this->registerCenter();

    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    [$payment, $account] = $this->asCenter($center['tenant'], fn (): array => whPending());

    [$body, $headers] = FakeGatewayProvider::callback((string) $payment->provider_payment_reference, 'paid', 20000, event: 'evt-private-payer-0770');

    whPost($center, $account->uuid, $body, $headers)->assertStatus(202);

    $signature = $headers['X-Test-Signature'];

    $this->asCenter($center['tenant'], function () use ($signature, $body): void {
        $secrets = ['secret-test-0001', 'whsec-test-0001', $signature, $body];

        foreach (DB::connection('tenant')->select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];

            if ($table === 'payment_gateway_accounts') {
                // Stored encrypted; the plaintext must not appear even there.
                $dump = (string) json_encode(DB::connection('tenant')->table($table)->get());
            } else {
                $dump = (string) json_encode(DB::connection('tenant')->table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }

            foreach ($secrets as $secret) {
                expect(str_contains($dump, $secret))->toBeFalse("secret material found in tenant table {$table}");
            }
        }

        // The event row is facts and codes, never the payload.
        $event = WebhookEvent::query()->firstOrFail();

        expect(array_keys($event->getAttributes()))->not->toContain('body')
            ->and(array_keys($event->getAttributes()))->not->toContain('payload');
    });

    foreach ($lines as $line) {
        expect(str_contains($line, 'whsec-test-0001'))->toBeFalse()
            ->and(str_contains($line, 'secret-test-0001'))->toBeFalse()
            ->and(str_contains($line, $signature))->toBeFalse();
    }
});

it('fails closed when the callback names no center that exists', function (): void {
    $this->registerCenter();

    [$body, $headers] = FakeGatewayProvider::callback('anything', 'paid', 20000);

    $this->call('POST', '/api/v1/payments/not-a-center-key/gateways/'.Str::uuid().'/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertStatus(404);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
