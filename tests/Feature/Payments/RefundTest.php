<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Authorization\Permission;
use App\Modules\Finance\Application\ExpectedCash;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentSource;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Payments\Infrastructure\Providers\FibProvider;
use App\Modules\Sales\Domain\Models\CashierShift;

/*
|--------------------------------------------------------------------------
| Refunds
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§9, 25–26, 66.
|
| Money back against one successful payment: never more than it, never a
| rewrite of it, and never a reopened invoice. A provider refund happens only
| where the provider really has one.
|
*/

it('refunds in parts up to the payment and no further, leaving the payment and the paid invoice alone', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);
        $paymentRow = Payment::query()->whereKey($payment->id)->firstOrFail()->getAttributes();

        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 5000, 'Service redone');
        app(RequestRefund::class)($payment, $owner, PaymentMethod::ManualElectronic, 10000, 'Transfer back');

        expect(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 5001, 'One too many'))
            ->toThrow(PaymentFailed::class, 'more than is left to refund');

        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 5000, 'The rest');

        expect(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 1, 'Nothing left'))
            ->toThrow(PaymentFailed::class, 'more than is left to refund');

        $summary = app(InvoiceSettlement::class)->forInvoice($invoice);

        expect(Refund::query()->where('status', RefundStatus::Succeeded->value)->sum('amount_minor'))->toEqual(20000)
            // The payment is history: not a column changed.
            ->and(Payment::query()->whereKey($payment->id)->firstOrFail()->getAttributes())->toBe($paymentRow)
            // Refunds do not reopen the invoice: still paid, nothing "owed" again.
            ->and($summary->state)->toBe(SettlementState::Paid)
            ->and($summary->availableCollectibleMinor)->toBe(0)
            ->and($summary->refundedMinor)->toBe(20000)
            ->and($summary->netCollectedMinor)->toBe(0)
            // One debit per refund.
            ->and(FinanceEntry::query()->where('kind', EntryKind::Refund->value)->count())->toBe(3);
    });
});

it('pays a cash refund out of the refunder\'s own drawer, and needs that drawer open', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);
        $shift = CashierShift::query()->where('active_user_id', $owner->id)->firstOrFail();

        $refund = app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 3000, 'Wrong add-on');

        expect($refund->cashier_shift_id)->toBe($shift->id)
            ->and(app(ExpectedCash::class)->forShift($shift)['expected'])->toBe(17000);

        $manager = $this->staffWith([Permission::PaymentRefund], 'manager@alpha.test');

        expect(fn () => app(RequestRefund::class)($payment, $manager, PaymentMethod::Cash, 1000, 'No drawer'))
            ->toThrow(PaymentFailed::class, 'Open your cashier shift');
    });
});

it('refunds through the provider only when it succeeds there, and leaves no debit when it does not', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $fake = $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $account = $this->gatewayAccount($seed['branch']);
        $invoice = $this->issuedInvoice($seed, $owner);

        $payment = app(InitiateGatewayPayment::class)->fromDesk($invoice, $account, $owner, 20000);
        app(GatewaySettlement::class)->apply($payment, new ProviderPaymentStatus((string) $payment->provider_payment_reference, ProviderState::Paid, 20000, 'IQD'), Actor::system('test'));
        $payment->refresh();

        $fake->refundSucceeds = false;
        $declined = app(RequestRefund::class)($payment, $owner, PaymentMethod::Gateway, 5000, 'Provider says no');

        $fake->refundSucceeds = true;
        $done = app(RequestRefund::class)($payment, $owner, PaymentMethod::Gateway, 5000, 'Provider refunds');

        expect($declined->status)->toBe(RefundStatus::Failed)
            ->and($done->status)->toBe(RefundStatus::Succeeded)
            ->and($done->provider_refund_reference)->not->toBeNull()
            ->and(FinanceEntry::query()->where('kind', EntryKind::Refund->value)->count())->toBe(1)
            ->and(FinanceEntry::query()->where('kind', EntryKind::Refund->value)->value('source_uuid'))->toBe($done->uuid)
            // The declined attempt released its reservation: 15,000 still refundable.
            ->and(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Gateway, 15001, 'Too much'))->toThrow(PaymentFailed::class);
    });
});

it('refuses a provider refund where the provider publishes no refund API, instead of pretending', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        // A succeeded FIB payment, written directly: FIB is not called here.
        $account = $this->gatewayAccount($seed['branch'], FibProvider::CODE);
        $account->forceFill(['environment' => GatewayEnvironment::Sandbox])->save();

        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'branch_id' => $invoice->branch_id,
            'amount_minor' => 20000,
            'currency' => 'IQD',
            'method' => PaymentMethod::Gateway,
            'status' => PaymentStatus::Succeeded,
            'source' => PaymentSource::Desk,
            'gateway_account_id' => GatewayAccount::query()->value('id'),
            'provider' => FibProvider::CODE,
            'provider_payment_reference' => 'fib-payment-0001',
            'initiated_at' => now(),
            'succeeded_at' => now(),
        ]);

        expect(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Gateway, 1000, 'Please'))
            ->toThrow(PaymentFailed::class, 'no refund API');

        expect(Refund::query()->count())->toBe(0);

        // Cash back at the desk is a real refund, and allowed.
        expect(app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 1000, 'Given back in cash')->status)
            ->toBe(RefundStatus::Succeeded);
    });
});

it('refunds only succeeded payments, needs a reason, and is idempotent', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        expect(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 1000, ''))
            ->toThrow(PaymentFailed::class, 'needs a reason');

        $first = app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 1000, 'Double tap', 'refund-press-0001');
        $again = app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 1000, 'Double tap', 'refund-press-0001');

        expect($again->uuid)->toBe($first->uuid)
            ->and(Refund::query()->count())->toBe(1)
            ->and(FinanceEntry::query()->where('kind', EntryKind::Refund->value)->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
