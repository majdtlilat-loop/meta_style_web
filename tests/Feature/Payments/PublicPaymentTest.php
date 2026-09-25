<?php

declare(strict_types=1);

use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\ManageGatewayAccount;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentSource;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A customer paying from their invoice link
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§29–30, 58, 60, 74, 84.
|
| The share secret is the authority; the server decides the amount. The
| invoice renders whether or not online payment is offered, and nothing
| internal ever reaches the page or the JSON.
|
*/

/**
 * @return array{invoice: Invoice, token: string, gateway: string}
 */
function ppIssue(bool $withGateway = true): array
{
    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();
    test()->openShift($seed['branch'], $owner);

    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    $issued = app(FinalizeSale::class)($sale, $owner);

    $gateway = $withGateway ? test()->gatewayAccount($seed['branch'])->uuid : '';

    return ['invoice' => $issued->invoice, 'token' => (string) $issued->shareToken, 'gateway' => $gateway];
}

/**
 * The public invoice API, on the center's own host with its slug as the
 * `{center}` segment (Phase 15, `ResolvePublicTenant`).
 */
function ppApi(array $center, string $token): string
{
    $slug = $center['registration']->requested_slug;

    return 'http://'.$slug.'.localhost:8000/api/v1/invoices/'.$slug.'/'.$token;
}

/**
 * The customer's invoice page. Since Phase 15 a center answers only on its own
 * host, `{slug}.<base>/i/{token}` (routes/center.php) — the pre-Phase-15
 * `/i/{publicKey}/{token}` path no longer exists.
 */
function ppPage(array $center, string $token): string
{
    return 'http://'.$center['registration']->requested_slug.'.localhost:8000/i/'.$token;
}

it('shows what is paid and left, and offers the branch\'s gateway only with online payments', function (): void {
    $center = $this->registerCenter();

    $issued = $this->asCenter($center['tenant'], function (): array {
        $this->fakeGateway();

        return ppIssue();
    });

    // No `payments`: the invoice and its balance, no pay option.
    $without = $this->getJson(ppApi($center, $issued['token']).'/payment')->assertOk()->json('data.payment');

    expect($without['online_options'])->toBe([])
        ->and($without['remaining']['amount'])->toBe(20000)
        ->and($without['state'])->toBe('unpaid');

    $this->get(ppPage($center, $issued['token']))->assertOk()->assertDontSee('Pay the remaining balance');

    $this->asCenter($center['tenant'], fn () => $this->grantPayments());

    $with = $this->getJson(ppApi($center, $issued['token']).'/payment')->assertOk()->json('data.payment');

    expect($with['online_options'])->toBe([['gateway' => $issued['gateway'], 'name' => 'TestPay']]);

    $this->get(ppPage($center, $issued['token']))->assertOk()->assertSee('Pay the remaining balance with TestPay');
});

it('starts a payment for exactly what is left, once, from the link', function (): void {
    $center = $this->registerCenter();

    $issued = $this->asCenter($center['tenant'], function (): array {
        $this->grantPayments();
        $this->fakeGateway();
        $issued = ppIssue();

        app(CollectDeskPayment::class)($issued['invoice'], $this->ownerWithCatalogAccess(), PaymentMethod::Cash, 5000);

        return $issued;
    });

    $key = (string) Str::uuid();

    $first = $this->postJson(ppApi($center, $issued['token']).'/payments', ['gateway' => $issued['gateway'], 'idempotency_token' => $key])->assertStatus(201);
    $second = $this->postJson(ppApi($center, $issued['token']).'/payments', ['gateway' => $issued['gateway'], 'idempotency_token' => $key])->assertStatus(201);

    expect(array_keys($first->json('data.payment')))->toBe(['code', 'link', 'expires_at'])
        ->and($second->json('data.payment.code'))->toBe($first->json('data.payment.code'));

    $this->asCenter($center['tenant'], function (): void {
        $payment = Payment::query()->where('method', PaymentMethod::Gateway->value)->firstOrFail();

        expect(Payment::query()->where('method', PaymentMethod::Gateway->value)->count())->toBe(1)
            ->and($payment->amount_minor)->toBe(15000)
            ->and($payment->source)->toBe(PaymentSource::PublicLink)
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->collected_by_id)->toBeNull();
    });
});

it('refuses a voided invoice, another branch\'s gateway and a disabled one, with one generic answer', function (): void {
    $center = $this->registerCenter();

    [$issued, $foreign] = $this->asCenter($center['tenant'], function (): array {
        $this->grantPayments();
        $this->fakeGateway();
        $issued = ppIssue();

        $foreign = $this->gatewayAccount($this->seedBranch('Mansour'))->uuid;

        return [$issued, $foreign];
    });

    $answer = fn (string $gateway) => $this->postJson(ppApi($center, $issued['token']).'/payments', ['gateway' => $gateway, 'idempotency_token' => (string) Str::uuid()]);

    $foreignAnswer = $answer($foreign)->assertStatus(422)->json('error');

    $this->asCenter($center['tenant'], function () use ($issued): void {
        $account = GatewayAccount::query()->where('uuid', $issued['gateway'])->firstOrFail();
        app(ManageGatewayAccount::class)->setEnabled($account, $this->ownerWithCatalogAccess(), false);
    });

    $disabledAnswer = $answer($issued['gateway'])->assertStatus(422)->json('error');

    $this->asCenter($center['tenant'], function () use ($issued): void {
        app(CloseSale::class)->void($issued['invoice']->sale()->firstOrFail(), $this->ownerWithCatalogAccess(), 'Voided before payment');
    });

    $voidedAnswer = $answer($issued['gateway'])->assertStatus(422)->json('error');

    // Nothing that says WHY: the same code and message every time.
    expect($foreignAnswer)->toBe($disabledAnswer)->toBe($voidedAnswer)
        ->and(json_encode($foreignAnswer))->not->toContain('branch');

    $this->asCenter($center['tenant'], fn () => expect(Payment::query()->where('method', 'gateway')->count())->toBe(0));

    // The voided invoice still renders — history is never hidden.
    $this->get(ppPage($center, $issued['token']))->assertOk();
});

it('publishes an allow-list: no ids, no staff, no internals, no manual transfer details', function (): void {
    $center = $this->registerCenter();

    $issued = $this->asCenter($center['tenant'], function (): array {
        $this->grantPayments();
        $this->fakeGateway();
        $issued = ppIssue();
        $owner = $this->ownerWithCatalogAccess();

        app(CollectDeskPayment::class)($issued['invoice'], $owner, PaymentMethod::ManualElectronic, 3000, 'PRIVATE-LABEL', 'PRIVATE-REF-777');

        return $issued;
    });

    $this->postJson(ppApi($center, $issued['token']).'/payments', ['gateway' => $issued['gateway'], 'idempotency_token' => (string) Str::uuid()])->assertStatus(201);

    $payload = $this->getJson(ppApi($center, $issued['token']).'/payment')->assertOk()->json('data.payment');
    $page = $this->get(ppPage($center, $issued['token']))->assertOk()->getContent();

    expect(array_keys($payload))->toBe(['state', 'voided', 'paid', 'pending', 'remaining', 'online_options', 'pending_online'])
        ->and(array_keys($payload['pending_online'][0]))->toBe(['code', 'link', 'expires_at']);

    $flat = json_encode($payload, JSON_THROW_ON_ERROR).$page;

    $this->asCenter($center['tenant'], function () use ($flat): void {
        foreach (Payment::query()->get() as $payment) {
            expect(str_contains($flat, $payment->uuid))->toBeFalse();
        }

        expect(str_contains($flat, 'PRIVATE-LABEL'))->toBeFalse()
            ->and(str_contains($flat, 'PRIVATE-REF-777'))->toBeFalse()
            ->and(str_contains($flat, 'secret-test-0001'))->toBeFalse()
            ->and(str_contains($flat, 'collected_by'))->toBeFalse()
            ->and(str_contains($flat, 'Owner'))->toBeFalse();
    });
});

it('starts the payment from the page and returns to the invoice, which settles nothing by itself', function (): void {
    $center = $this->registerCenter();

    $issued = $this->asCenter($center['tenant'], function (): array {
        $this->grantPayments();
        $this->fakeGateway();

        return ppIssue();
    });

    $this->post(ppPage($center, $issued['token']).'/pay', [
        'gateway' => $issued['gateway'],
        'payment_key' => (string) Str::uuid(),
    ])->assertRedirect(ppPage($center, $issued['token']));

    // Coming back to the page — even with a "success"-looking query — proves nothing.
    $this->get(ppPage($center, $issued['token']).'?status=success&paid=1')->assertOk()->assertSee('CODE');

    $this->asCenter($center['tenant'], function (): void {
        expect(Payment::query()->firstOrFail()->status)->toBe(PaymentStatus::Pending);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
