<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Livewire\Center\InvoicePayments;
use App\Livewire\Center\Receipts;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Application\UsableGateways;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\Payment;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Receipts and the desk money panel
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§3, 28, 59–60. What was taken and given back at a
| branch, by branch-local day, added up by Payments per currency; the desk
| offers only gateways the Action would accept; "what is left" and "what can
| still be refunded" are the server's figures, written back as text.
|
*/

it('lists a branch\'s payments and refunds by branch-local day, with totals per method', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $first = $this->issuedInvoice($seed, $owner);
        $second = $this->issuedInvoice($seed, $owner);

        $cash = app(CollectDeskPayment::class)($first, $owner, PaymentMethod::Cash, 12000);
        app(CollectDeskPayment::class)($second, $owner, PaymentMethod::ManualElectronic, 8000, 'FIB transfer', 'TX-77');
        app(RequestRefund::class)($cash, $owner, PaymentMethod::Cash, 2000, 'Charged for a wash not done');

        $query = app(PaymentsQuery::class);
        $today = $this->branchToday($seed['branch']);
        $branch = $seed['branch']->uuid;

        expect($query->receipts($owner, $branch, $today, $today)->total())->toBe(2)
            ->and($query->receipts($owner, $branch, $today, $today, ['method' => 'cash'])->total())->toBe(1)
            ->and($query->receipts($owner, $branch, $today, $today, ['term' => $second->number])->items()[0]->uuid ?? null)
            ->toBe(Payment::query()->where('invoice_id', $second->id)->value('uuid'))
            ->and($query->refundsIn($owner, $branch, $today, $today)->total())->toBe(1);

        $totals = $query->receiptTotals($owner, $branch, $today, $today);

        expect($totals['received']['total'])->toBe([['currency' => 'IQD', 'total_minor' => 20000, 'count' => 2]])
            ->and(count($totals['received']['by_method']))->toBe(2)
            ->and($totals['refunded']['total'])->toBe([['currency' => 'IQD', 'total_minor' => 2000, 'count' => 1]])
            ->and($totals['pending']['total'])->toBe([]);

        expect(fn () => $query->receipts($owner, $branch, 'yesterday', $today))->toThrow(PaymentFailed::class, 'Dates are YYYY-MM-DD.')
            ->and(fn () => $query->receipts($this->staffWith([Permission::SaleView], 'seller@alpha.test'), $branch, $today, $today))
            ->toThrow(AuthorizationException::class);

        $this->actingAs($owner, 'web');

        Livewire::test(Receipts::class)
            ->assertSee('TX-77')
            ->assertSee($second->number)
            ->assertViewHas('totals', fn (array $totals): bool => $totals['received'] === '20,000 IQD' && $totals['refunded'] === '2,000 IQD')
            ->call('showList', 'refunds')
            ->assertSee('Charged for a wash not done')
            ->assertDontSee('TX-77');

        // Without the permission: a clear state, and nothing read.
        $this->actingAs($this->staffWith([Permission::SaleView], 'viewer@alpha.test'), 'web');

        Livewire::test(Receipts::class)
            ->assertViewHas('denied', true)
            ->assertViewHas('rows', [])
            ->assertDontSee('TX-77');
    });
});

it('offers the desk only gateways the invoice can use, and prefills what is left and what can be refunded', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        $usable = $this->gatewayAccount($seed['branch']);
        $this->gatewayAccount($seed['branch'], 'zaincash');
        $off = $this->gatewayAccount($seed['branch'], 'fib', enabled: false);
        $off->forceFill(['environment' => GatewayEnvironment::Live])->save();

        expect(array_map(fn ($account): string => $account->uuid, app(UsableGateways::class)->forInvoice($invoice)))->toBe([$usable->uuid]);

        $this->actingAs($owner, 'web');

        $panel = Livewire::test(InvoicePayments::class, ['invoice' => $invoice->uuid])
            ->assertViewHas('gateways', fn (array $gateways): bool => array_column($gateways, 'uuid') === [$usable->uuid])
            ->assertViewHas('summary', fn (array $summary): bool => $summary['state'] === 'unpaid' && $summary['state_label'] === 'Unpaid')
            ->call('fillRemaining', 'cashAmount')
            ->assertSet('cashAmount', '20000')
            ->set('cashAmount', '12000')
            ->call('collectCash')
            ->assertSet('error', '')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['state'] === 'partial');

        $cash = Payment::query()->where('method', 'cash')->sole();

        // Choosing a payment to refund prefills what it can still give back,
        // and never offers the provider for money taken in cash.
        $panel->set('refundPayment', $cash->uuid)
            ->assertSet('refundAmount', '12000')
            ->assertSet('refundMethod', 'cash')
            ->assertViewHas('refundMethods', fn (array $methods): bool => array_column($methods, 'value') === ['cash', 'manual_electronic'])
            ->set('refundAmount', '2000')
            ->set('refundReason', 'Charged for a wash not done')
            ->call('refund')
            ->assertSet('error', '')
            ->set('refundPayment', $cash->uuid)
            ->assertSet('refundAmount', '10000')
            ->call('fillRemaining', 'secret')
            ->assertSet('error', '');
    });
});

it('locks receipts for a center that can take no payment and never took one, and keeps them readable after a downgrade', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $tenantId = (string) app(TenantContext::class)->id();
        $this->actingAs($owner, 'web');

        // Neither the desk (`pos`) nor online (`payments`), and no history: the
        // upgrade state, with nothing read.
        $this->revokeEntitlement('pos');

        expect(app(PaymentsQuery::class)->hasHistory())->toBeFalse();

        Livewire::test(Receipts::class)
            ->assertSee(__('manager_features.ui.eyebrow'))
            ->assertViewMissing('rows');

        // POS back, money taken, POS withdrawn again: the receipts stay.
        TenantEntitlementOverride::query()->where('tenant_id', $tenantId)->delete();
        app(Entitlements::class)->invalidate($tenantId);

        $invoice = $this->issuedInvoice($seed, $owner);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::ManualElectronic, 5000, 'Bank transfer', 'TX-DOWN');

        $this->revokeEntitlement('pos');

        expect(app(Entitlements::class)->enabled('pos'))->toBeFalse()
            ->and(app(PaymentsQuery::class)->hasHistory())->toBeTrue();

        Livewire::test(Receipts::class)
            ->assertViewHas('denied', false)
            ->assertSee('TX-DOWN')
            ->assertSee($invoice->number);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
