<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Money taken at the desk
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§5–14.
|
| Cash and manually confirmed transfers: `pos` only, succeeded on the spot,
| never more than is left, one ledger collection each. The seeded trial plan
| owns `pos` and not `payments` — pinned in the first test — so "cash needs no
| online-payments entitlement" is proved, not assumed.
|
*/

it('takes cash with POS alone, succeeds on the spot, and records one collection against the drawer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        // The baseline this test depends on.
        expect(app(Entitlements::class)->enabled('pos'))->toBeTrue()
            ->and(app(Entitlements::class)->enabled('payments'))->toBeFalse();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        $shift = CashierShift::query()->where('active_user_id', $owner->id)->firstOrFail();
        $entries = FinanceEntry::query()->where('source_uuid', $payment->uuid)->get();

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->succeeded_at)->not->toBeNull()
            ->and($payment->cashier_shift_id)->toBe($shift->id)
            ->and($payment->currency)->toBe('IQD')
            ->and($entries)->toHaveCount(1)
            ->and($entries->first()?->kind)->toBe(EntryKind::Collection)
            ->and($entries->first()?->amount_minor)->toBe(20000)
            ->and($entries->first()?->cashier_shift_id)->toBe($shift->id)
            ->and(TenantAuditLog::query()->where('action', 'payment.cash_recorded')->count())->toBe(1);
    });
});

it('records a staff-confirmed transfer without a drawer, and says so', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::ManualElectronic, 5000))
            ->toThrow(PaymentFailed::class, 'how it was paid');

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::ManualElectronic, 5000, 'FIB transfer', 'TRX-99');

        expect($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->method)->toBe(PaymentMethod::ManualElectronic)
            ->and($payment->manual_method_label)->toBe('FIB transfer')
            ->and($payment->manual_reference)->toBe('TRX-99')
            // Not drawer cash, and no provider claims to have verified it.
            ->and($payment->cashier_shift_id)->toBeNull()
            ->and($payment->provider)->toBeNull()
            ->and($payment->gateway_account_id)->toBeNull();
    });
});

it('splits one invoice across several payments: partial, then paid, never over', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $before = Invoice::query()->whereKey($invoice->id)->firstOrFail()->getAttributes();

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 8000);
        $partial = app(InvoiceSettlement::class)->forInvoice($invoice);

        expect($partial->state)->toBe(SettlementState::Partial)
            ->and($partial->succeededMinor)->toBe(8000)
            ->and($partial->availableCollectibleMinor)->toBe(12000);

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 12001))
            ->toThrow(PaymentFailed::class, 'more than is left');

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::ManualElectronic, 12000, 'Bank transfer');
        $paid = app(InvoiceSettlement::class)->forInvoice($invoice);

        expect($paid->state)->toBe(SettlementState::Paid)
            ->and($paid->availableCollectibleMinor)->toBe(0)
            ->and(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(2)
            // The invoice is exactly what was published.
            ->and(Invoice::query()->whereKey($invoice->id)->firstOrFail()->getAttributes())->toBe($before);

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 1))
            ->toThrow(PaymentFailed::class, 'more than is left');
    });
});

it('refuses zero, and refuses cash without the collector\'s open shift', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 0))
            ->toThrow(PaymentFailed::class, 'more than zero');

        $shift = CashierShift::query()->where('active_user_id', $owner->id)->firstOrFail();
        app(ManageCashierShift::class)->close($shift, $owner);

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 1000))
            ->toThrow(PaymentFailed::class, 'Open your cashier shift');

        expect(Payment::query()->count())->toBe(0);
    });
});

it('takes no payment against a voided sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        app(CloseSale::class)->void($invoice->sale()->firstOrFail(), $owner, 'Rang the wrong service');

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 1000))
            ->toThrow(PaymentFailed::class, 'voided');

        expect(app(InvoiceSettlement::class)->forInvoice($invoice)->availableCollectibleMinor)->toBe(0);
    });
});

it('returns the same payment for a repeated submission, and refuses a token reused for different money', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        $first = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 5000, null, null, 'desk-press-0001');
        $again = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 5000, null, null, 'desk-press-0001');

        expect($again->uuid)->toBe($first->uuid)
            ->and(Payment::query()->count())->toBe(1)
            ->and(FinanceEntry::query()->count())->toBe(1);

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 6000, null, null, 'desk-press-0001'))
            ->toThrow(PaymentFailed::class, 'already used');
    });
});

it('needs POS, the collect permission and the branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        $viewer = $this->staffWith([Permission::SaleView, Permission::PaymentView], 'viewer@alpha.test');

        expect(fn () => app(CollectDeskPayment::class)($invoice, $viewer, PaymentMethod::Cash, 1000))
            ->toThrow(AuthorizationException::class);

        $elsewhere = $this->seedBranch('Mansour');
        $cashier = $this->staffWith([Permission::PaymentCollect, Permission::CashierShiftManage], 'cashier@alpha.test');
        $cashier->forceFill(['all_branches' => false])->save();
        $cashier->syncBranchScope([$elsewhere->id]);
        $cashier->forgetPermissionCache();

        expect(fn () => app(CollectDeskPayment::class)($invoice, $cashier->fresh() ?? $cashier, PaymentMethod::Cash, 1000))
            ->toThrow(AuthorizationException::class);

        $this->revokeEntitlement('pos');

        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 1000))
            ->toThrow(EntitlementRequired::class);

        expect(Payment::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
