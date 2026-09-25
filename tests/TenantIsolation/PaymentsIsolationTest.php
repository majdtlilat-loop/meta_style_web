<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Payments\Domain\Models\WebhookEvent;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGatewayProvider;

/*
|--------------------------------------------------------------------------
| Payments tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/19-PAYMENTS.md §§21, 64.
|
| A provider callback names a center by its public key and an account by its
| public uuid. Two centers can hold the same provider reference, the same row
| ids and the same account id — and a callback settles exactly one payment, in
| exactly the center it names, or none.
|
*/

/**
 * A pending TestPay payment whose provider reference is forced to `$reference`,
 * so two centers genuinely share one.
 *
 * @return array{payment: Payment, account: GatewayAccount, token: string}
 */
function piPending(string $reference): array
{
    test()->grantPayments();
    test()->fakeGateway();

    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();
    test()->openShift($seed['branch'], $owner);

    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    $issued = app(FinalizeSale::class)($sale, $owner);

    $account = test()->gatewayAccount($seed['branch']);
    $payment = app(InitiateGatewayPayment::class)->fromDesk($issued->invoice, $account, $owner, 20000);

    $payment->forceFill(['provider_payment_reference' => $reference])->save();

    return ['payment' => $payment, 'account' => $account, 'token' => (string) $issued->shareToken];
}

function piCallback(array $center, string $account, string $reference): TestResponse
{
    [$body, $headers] = FakeGatewayProvider::callback($reference, 'paid', 20000);

    // Phase 15: on the center's own host, its slug in the path (`public.tenant`).
    return test()->call('POST', 'http://'.$center['registration']->requested_slug.'.localhost:8000/api/v1/payments/'.$center['registration']->requested_slug.'/gateways/'.$account.'/webhook', [], [], [], test()->transformHeadersToServerVars($headers), $body);
}

it('settles only the named center\'s payment when two centers share a provider reference and row ids', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => piPending('shared-ref-0001'));
    $b = $this->asCenter($beta['tenant'], fn (): array => piPending('shared-ref-0001'));

    // Same numeric ids and the same provider reference, in two databases.
    expect($a['payment']->id)->toBe($b['payment']->id)
        ->and($a['account']->id)->toBe($b['account']->id)
        ->and($a['account']->uuid)->not->toBe($b['account']->uuid);

    piCallback($alpha, $a['account']->uuid, 'shared-ref-0001')->assertStatus(202);

    $this->asCenter($alpha['tenant'], function (): void {
        expect(Payment::query()->firstOrFail()->status)->toBe(PaymentStatus::Succeeded)
            ->and(WebhookEvent::query()->count())->toBe(1);
    });

    $this->asCenter($beta['tenant'], function (): void {
        expect(Payment::query()->firstOrFail()->status)->toBe(PaymentStatus::Pending)
            ->and(WebhookEvent::query()->count())->toBe(0);
    });
});

it('refuses one center\'s account under another center\'s key, and changes neither', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => piPending('alpha-ref-0001'));
    $this->asCenter($beta['tenant'], fn (): array => piPending('beta-ref-0001'));

    // Alpha's account uuid, signed with the shared test secret, sent to Beta.
    piCallback($beta, $a['account']->uuid, 'alpha-ref-0001')->assertStatus(400);

    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], function (): void {
            expect(Payment::query()->firstOrFail()->status)->toBe(PaymentStatus::Pending)
                ->and(WebhookEvent::query()->count())->toBe(0);
        });
    }
});

it('never resolves one center\'s invoice link for payment inside another center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => piPending('alpha-ref-0002'));
    $this->asCenter($beta['tenant'], fn (): array => piPending('beta-ref-0002'));

    // Phase 15: the public API resolves on a center's own host, whose slug the
    // `{center}` segment must repeat.
    $alphaSlug = $alpha['registration']->requested_slug;
    $betaSlug = $beta['registration']->requested_slug;
    $alphaApi = 'http://'.$alphaSlug.'.localhost:8000/api/v1/invoices/'.$alphaSlug.'/';
    $betaApi = 'http://'.$betaSlug.'.localhost:8000/api/v1/invoices/'.$betaSlug.'/';

    $this->getJson($alphaApi.$a['token'].'/payment')->assertOk();
    $this->getJson($betaApi.$a['token'].'/payment')->assertStatus(404);
    // Alpha's slug presented on Beta's host is not a way across either.
    $this->getJson('http://'.$betaSlug.'.localhost:8000/api/v1/invoices/'.$alphaSlug.'/'.$a['token'].'/payment')->assertStatus(404);

    $this->postJson($betaApi.$a['token'].'/payments', ['gateway' => $a['account']->uuid, 'idempotency_token' => 'cross-center-0001'])
        ->assertStatus(404);

    $this->asCenter($beta['tenant'], function (): void {
        expect(Payment::query()->count())->toBe(1);
    });
});

it('fails closed when no center is bound, and leaves none bound after payment work', function (): void {
    $center = $this->registerCenter();

    expect(fn (): int => Payment::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => Refund::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => GatewayAccount::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => WebhookEvent::query()->count())->toThrow(TenantConnectionNotInitialized::class);

    $this->asCenter($center['tenant'], fn (): array => piPending('ref-bound-0001'));

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('puts every payments table in the center database, with no tenant column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        foreach (['payment_gateway_accounts', 'payments', 'refunds', 'payment_webhook_events'] as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue()
                ->and(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))->toBeFalse();
        }

        // Settlement lives on Payments; the Sales tables stay free of it.
        foreach (['sales', 'invoices'] as $table) {
            foreach (['paid_minor', 'payment_status', 'amount_paid_minor', 'balance_minor'] as $column) {
                expect(Schema::connection('tenant')->hasColumn($table, $column))->toBeFalse("{$table}.{$column}");
            }
        }
    });

    // And nothing of it in the control plane: the platform holds no center's money.
    foreach (['payments', 'refunds', 'payment_gateway_accounts', 'finance_entries'] as $table) {
        expect(Schema::connection('control')->hasTable($table))->toBeFalse("control has {$table}");
    }
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
