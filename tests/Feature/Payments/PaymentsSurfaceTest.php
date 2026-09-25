<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\InvoicePayments;
use App\Livewire\Center\PaymentGateways;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeGatewayProvider;

/*
|--------------------------------------------------------------------------
| Payments surfaces
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§57–60.
|
| The API and the desk panel call the same Actions. These drive both ends and
| assert outcomes: a split bill over HTTP, a refund, the error codes a client
| branches on, and a gateway account that goes in with its secrets and never
| comes back out with them.
|
*/

it('splits a bill over the API: cash, then a transfer, then a refund — with the codes a client branches on', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $invoice = $this->asCenter($center['tenant'], fn () => $this->issuedInvoice($this->seedBookableCenter(), $this->ownerWithCatalogAccess()));

    $cash = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments", ['method' => 'cash', 'amount_minor' => 15000, 'idempotency_token' => 'surface-cash-0001'])
        ->assertStatus(201)
        ->assertJsonPath('data.payment.status', 'succeeded')
        ->assertJsonPath('data.payment.method', 'cash')
        ->assertJsonPath('data.payment.amount.amount', 15000);

    // Pressed twice: the same payment.
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments", ['method' => 'cash', 'amount_minor' => 15000, 'idempotency_token' => 'surface-cash-0001'])
        ->assertStatus(201)
        ->assertJsonPath('data.payment.uuid', $cash->json('data.payment.uuid'));

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments", ['method' => 'manual_electronic', 'amount_minor' => 6000, 'manual_method_label' => 'Bank transfer'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PAYMENTS.POLICY_VIOLATION');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments", ['method' => 'manual_electronic', 'amount_minor' => 5000, 'manual_method_label' => 'Bank transfer', 'manual_reference' => 'TRX-77'])
        ->assertStatus(201);

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments")
        ->assertStatus(200)
        ->assertJsonPath('data.settlement.state', 'paid')
        ->assertJsonPath('data.settlement.available_collectible.amount', 0)
        ->assertJsonCount(2, 'data.payments');

    $cashUuid = (string) $cash->json('data.payment.uuid');

    $refund = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/payments/{$cashUuid}/refunds", ['method' => 'cash', 'amount_minor' => 5000, 'reason' => 'Service partly redone'])
        ->assertStatus(201)
        ->assertJsonPath('data.refund.status', 'succeeded');

    $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/refunds/'.$refund->json('data.refund.uuid'))
        ->assertStatus(200)
        ->assertJsonPath('data.refund.reason', 'Service partly redone');

    // The payment stays succeeded, with its refund beside it; the invoice stays paid.
    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/payments/{$cashUuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.payment.status', 'succeeded')
        ->assertJsonCount(1, 'data.payment.refunds');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments")
        ->assertJsonPath('data.settlement.state', 'paid')
        ->assertJsonPath('data.settlement.refunded.amount', 5000)
        ->assertJsonPath('data.settlement.net_collected.amount', 15000);

    // Money is whole minor units — never a fraction — and things that do not exist are 404.
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments", ['method' => 'cash', 'amount_minor' => 150.5])
        ->assertStatus(422);

    $this->withHeaders($headers)->getJson('/api/v1/tenant/payments/'.Str::uuid())->assertStatus(404);
    $this->withHeaders($headers)->getJson('/api/v1/tenant/invoices/'.Str::uuid().'/payments')->assertStatus(404);

    $this->asCenter($center['tenant'], function (): void {
        expect(Payment::query()->count())->toBe(2)
            ->and(Refund::query()->count())->toBe(1)
            ->and(FinanceEntry::query()->count())->toBe(3);
    });
});

it('never exposes a numeric id on a payment or a refund', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $invoice = $this->asCenter($center['tenant'], fn () => $this->issuedInvoice($this->seedBookableCenter(), $this->ownerWithCatalogAccess()));

    $payment = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoice->uuid}/payments", ['method' => 'cash', 'amount_minor' => 20000])
        ->assertStatus(201)
        ->json('data.payment');

    foreach (['id', 'invoice_id', 'branch_id', 'cashier_shift_id', 'gateway_account_id', 'idempotency_token', 'collected_by_id'] as $key) {
        expect(array_key_exists($key, $payment))->toBeFalse("payment presents {$key}");
    }
});

it('takes a configured gateway\'s secrets in over the API, and never gives them back', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $branch = $this->asCenter($center['tenant'], function () {
        $this->grantPayments();

        return $this->seedBookableCenter()['branch'];
    });

    $this->fakeGateway();

    $configured = $this->withHeaders($headers)
        ->putJson("/api/v1/tenant/branches/{$branch->uuid}/payment-gateways/".FakeGatewayProvider::CODE, [
            'environment' => 'sandbox',
            'display_name' => 'Main TestPay',
            'credentials' => ['client_id' => 'client-surface-9876', 'client_secret' => 'secret-surface-api-5555', 'webhook_secret' => 'whsec-surface-api-4444'],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.account.configured', true)
        ->assertJsonPath('data.account.safe_identifier', '••••9876');

    $listed = $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/branches/{$branch->uuid}/payment-gateways")
        ->assertStatus(200);

    $uuid = (string) $configured->json('data.account.uuid');

    $this->withHeaders($headers)->postJson("/api/v1/tenant/payment-gateways/{$uuid}/enable")->assertStatus(200)->assertJsonPath('data.account.enabled', true);
    $disabled = $this->withHeaders($headers)->postJson("/api/v1/tenant/payment-gateways/{$uuid}/disable")->assertStatus(200)->assertJsonPath('data.account.enabled', false);

    foreach ([$configured->getContent(), $listed->getContent(), $disabled->getContent()] as $body) {
        expect(str_contains((string) $body, 'secret-surface-api-5555'))->toBeFalse()
            ->and(str_contains((string) $body, 'whsec-surface-api-4444'))->toBeFalse()
            ->and(str_contains((string) $body, 'client-surface-9876'))->toBeFalse()
            ->and(str_contains((string) $body, '"credentials"'))->toBeFalse();
    }

    // The provider list names fields; it never carries values.
    expect(collect($listed->json('data.providers'))->firstWhere('code', FakeGatewayProvider::CODE)['credential_fields'])
        ->toBe(['client_id', 'client_secret', 'webhook_secret']);

    $this->asCenter($center['tenant'], function (): void {
        expect(GatewayAccount::query()->firstOrFail()->credentials['client_secret'] ?? null)->toBe('secret-surface-api-5555');
    });
});

it('collects and refunds from the desk panel, and shows nothing to someone who may not see money', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $this->actingAs($owner, 'web');

        $panel = Livewire::test(InvoicePayments::class, ['invoice' => $invoice->uuid])
            ->set('cashAmount', '12000')
            ->call('collectCash')
            ->assertSet('error', '')
            ->set('manualAmount', '9000')
            ->set('manualLabel', 'FIB transfer')
            ->call('collectManual')
            // Only 8,000 is left: refused by the Action, shown to the desk.
            ->assertSet('error', 'That is more than is left to collect on this invoice.')
            ->set('manualAmount', '8000')
            ->call('collectManual')
            ->assertSet('error', '');

        $cash = Payment::query()->where('method', 'cash')->firstOrFail();

        $panel->set('refundPayment', $cash->uuid)
            ->set('refundAmount', '2000')
            ->set('refundMethod', 'cash')
            ->set('refundReason', 'Charged for a wash not done')
            ->call('refund')
            ->assertSet('error', '');

        expect((int) Payment::query()->where('status', PaymentStatus::Succeeded->value)->sum('amount_minor'))->toBe(20000)
            ->and(Refund::query()->value('amount_minor'))->toBe(2000);

        // An invoice uuid that is not the panel's cannot be refunded through it.
        $foreign = app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::Cash, 5000);

        $panel->set('refundPayment', $foreign->uuid)
            ->set('refundAmount', '1000')
            ->set('refundReason', 'Not this invoice')
            ->call('refund')
            ->assertSet('error', 'That could not be found.');

        $stranger = $this->staffWith([Permission::SaleView], 'stranger@alpha.test');
        $this->actingAs($stranger, 'web');

        Livewire::test(InvoicePayments::class, ['invoice' => $invoice->uuid])
            ->assertSet('error', 'You may not view payments for this invoice.')
            ->assertDontSee('Charged for a wash not done');
    });
});

it('clears typed gateway secrets from the settings screen, whatever happens', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->actingAs($owner, 'web');

        Livewire::test(PaymentGateways::class)
            ->set('provider', FakeGatewayProvider::CODE)
            ->set('displayName', 'Desk TestPay')
            ->set('credentials', ['client_id' => 'client-lw-1234', 'client_secret' => 'secret-livewire-7777', 'webhook_secret' => 'whsec-livewire-8888'])
            ->call('configure')
            ->assertSet('error', '')
            ->assertSet('credentials', [])
            ->assertDontSee('secret-livewire-7777')
            ->assertDontSee('whsec-livewire-8888')
            // A refusal clears them too.
            ->set('provider', 'zaincash')
            ->set('credentials', ['merchant_id' => 'zc-1', 'secret' => 'secret-refused-6666'])
            ->call('configure')
            ->assertSet('credentials', [])
            ->assertDontSee('secret-refused-6666');

        expect(GatewayAccount::query()->count())->toBe(1);
    });
});

it('keeps staff payment routes authenticated and callbacks and the public payment page unauthenticated', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());

    $staff = $routes->filter(fn ($route): bool => str_contains((string) $route->getActionName(), 'PaymentController@')
        && ! str_contains((string) $route->getActionName(), 'PublicPaymentController')
        || str_contains((string) $route->getActionName(), 'GatewayAccountController@')
        || str_contains((string) $route->getActionName(), 'Livewire\Center\PaymentGateways'));

    expect($staff->count())->toBeGreaterThanOrEqual(12);

    foreach ($staff as $route) {
        expect($route->gatherMiddleware())->toContain(str_starts_with($route->uri(), 'api/') ? 'auth:sanctum' : 'auth:web');
    }

    $public = $routes->filter(fn ($route): bool => str_contains((string) $route->getActionName(), 'PaymentWebhookController')
        || str_contains((string) $route->getActionName(), 'PublicPaymentController@')
        || str_contains((string) $route->getActionName(), 'PublicInvoicePageController@pay'));

    expect($public->count())->toBe(4);

    foreach ($public as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('public.tenant');

        foreach ($middleware as $name) {
            expect(str_starts_with((string) $name, 'auth'))->toBeFalse("{$route->uri()} carries {$name}");
        }
    }

    // Every public payment write is throttled.
    foreach ($public->filter(fn ($route): bool => in_array('POST', $route->methods(), true)) as $route) {
        expect(collect($route->gatherMiddleware())->contains(fn ($name): bool => str_starts_with((string) $name, 'throttle:')))->toBeTrue();
    }
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
