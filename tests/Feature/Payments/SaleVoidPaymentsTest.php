<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;

/*
|--------------------------------------------------------------------------
| Voiding a sale that money has touched
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§18, 27, 51, 73.
|
| Sales asks, through its own `SaleVoidGuard` contract, and Payments answers.
| No automatic refund, no automatic cancellation — and Sales still imports
| nothing from Payments (the architecture tests hold that line).
|
*/

it('voids a finalized sale nobody has paid anything on', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        $voided = app(CloseSale::class)->void($invoice->sale()->firstOrFail(), $owner, 'Wrong customer');

        expect($voided->status)->toBe(SaleStatus::Voided);
    });
});

it('refuses to void while money is collected, and allows it once that money is refunded', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $sale = $invoice->sale()->firstOrFail();

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 12000);

        expect(fn () => app(CloseSale::class)->void($sale, $owner, 'Customer changed their mind'))
            ->toThrow(SaleFailed::class, 'Refund it before voiding');

        // The refusal touched nothing, and refunded nothing on its own.
        expect($sale->fresh()?->status)->toBe(SaleStatus::Finalized)
            ->and(Refund::query()->count())->toBe(0);

        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 12000, 'Returned before voiding');

        expect(app(CloseSale::class)->void($sale->fresh() ?? $sale, $owner, 'Customer changed their mind')->status)
            ->toBe(SaleStatus::Voided);
    });
});

it('refuses to void while an online payment is still on its way', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $sale = $invoice->sale()->firstOrFail();

        $payment = app(InitiateGatewayPayment::class)->fromDesk($invoice, $this->gatewayAccount($seed['branch']), $owner, 20000);

        expect(fn () => app(CloseSale::class)->void($sale, $owner, 'Leaving'))
            ->toThrow(SaleFailed::class, 'online payment in progress');

        // Resolved — the provider reports it declined — and the void goes through.
        app(GatewaySettlement::class)->apply($payment, new ProviderPaymentStatus((string) $payment->provider_payment_reference, ProviderState::Declined, null, null), Actor::system('test'));

        expect(app(CloseSale::class)->void($sale->fresh() ?? $sale, $owner, 'Leaving')->status)->toBe(SaleStatus::Voided);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
