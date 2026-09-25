<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Authorization\Permission;
use App\Livewire\Center\PointOfSale;
use App\Modules\Finance\Application\Actions\CloseShiftWithCount;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\ExpectedCash;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\GatewaySettlement;
use App\Modules\Payments\Domain\Data\ProviderPaymentStatus;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\ProviderState;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Counting the drawer
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§31–33, 69.
|
|   expected = opening + cash collected − cash refunded − drawer expenses
|              + their reversals
|
| Only cash. The count is recorded with a snapshot of every component, and a
| difference is a fact to report, never a reason to refuse the close.
|
*/

it('expects exactly the cash that went through this drawer, and records the count', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $shift = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner, null, null, 50000);

        $cash = app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::Cash, 20000);
        $stillOwed = $this->issuedInvoice($seed, $owner);
        app(RequestRefund::class)($cash, $owner, PaymentMethod::Cash, 2000, 'Partly redone');

        // Not the drawer: a confirmed transfer, and an online payment.
        app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::ManualElectronic, 20000, 'Bank transfer');
        $online = app(InitiateGatewayPayment::class)->fromDesk($this->issuedInvoice($seed, $owner), $this->gatewayAccount($seed['branch']), $owner, 20000);
        app(GatewaySettlement::class)->apply($online, new ProviderPaymentStatus((string) $online->provider_payment_reference, ProviderState::Paid, 20000, 'IQD'), Actor::system('test'));

        $category = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
        $drawer = app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 3000, 'method' => 'cash', 'description' => 'Milk for coffee', 'from_drawer' => true], $owner);
        app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 90000, 'method' => 'cash', 'description' => 'Rent, paid at the bank'], $owner);
        app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 1000, 'method' => 'cash', 'description' => 'Entered by mistake', 'from_drawer' => true], $owner);
        app(RecordExpense::class)->void(Expense::query()->where('description', 'Entered by mistake')->firstOrFail(), $owner, 'Never left the drawer');

        $figures = app(ExpectedCash::class)->forShift($shift);

        // 50,000 + 20,000 − 2,000 − 4,000 + 1,000 — nothing electronic, no bank rent.
        expect($figures)->toBe(['opening' => 50000, 'collected' => 20000, 'refunded' => 2000, 'expenses' => 4000, 'reversals' => 1000, 'expected' => 65000]);

        // The cashier counts 64,500: 500 short. Recorded, not refused, not "fixed".
        $count = app(CloseShiftWithCount::class)($shift, $owner, 64500, 'Counted twice');

        expect($count->expected_cash_minor)->toBe(65000)
            ->and($count->counted_cash_minor)->toBe(64500)
            ->and($count->variance_minor)->toBe(-500)
            ->and($count->opening_cash_minor)->toBe(50000)
            ->and($count->cash_collected_minor)->toBe(20000)
            ->and($shift->fresh()?->status)->toBe(ShiftStatus::Closed)
            ->and($drawer->cashier_shift_id)->toBe($shift->id);

        // Closed: no more cash into this drawer, and no second count.
        expect(fn () => app(CollectDeskPayment::class)($stillOwed, $owner, PaymentMethod::Cash, 1000))
            ->toThrow(PaymentFailed::class, 'Open your cashier shift');

        expect(fn () => app(CloseShiftWithCount::class)($shift->fresh() ?? $shift, $owner, 0))
            ->toThrow(FinanceFailed::class, 'already closed');

        expect(ShiftReconciliation::query()->count())->toBe(1);
    });
});

it('lets only a supervisor count somebody else\'s drawer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();

        $cashier = $this->staffWith([Permission::CashierShiftManage, Permission::SaleView], 'cashier@alpha.test');
        $colleague = $this->staffWith([Permission::CashierShiftManage], 'colleague@alpha.test');
        $shift = app(ManageCashierShift::class)->open($seed['branch']->uuid, $cashier, null, null, 10000);

        expect(fn () => app(CloseShiftWithCount::class)($shift, $colleague, 10000))
            ->toThrow(AuthorizationException::class);

        $owner = $this->ownerWithCatalogAccess();

        expect(app(CloseShiftWithCount::class)($shift, $owner, 10000)->variance_minor)->toBe(0);
    });
});

it('asks the till for a count with Finance, and closes the Phase 9 way without it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->actingAs($owner, 'web');

        // Without Finance: a plain close, no count.
        $plain = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);

        Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('closeShift', $plain->uuid)
            ->assertSet('error', '');

        expect($plain->fresh()?->status)->toBe(ShiftStatus::Closed)
            ->and(ShiftReconciliation::query()->count())->toBe(0);

        // With Finance: opening cash at open, a blind count at close.
        $this->grantFinance();

        $component = Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->set('openingCash', '25000')
            ->call('openShift')
            ->assertSet('error', '');

        $counted = CashierShift::query()->where('active_user_id', $owner->id)->firstOrFail();

        expect($counted->opening_cash_minor)->toBe(25000);

        $component->call('closeShift', $counted->uuid)->assertSet('error', 'Count the cash in the drawer before closing the shift.');

        $component->set('countedCash', '25000')->call('closeShift', $counted->uuid)->assertSet('error', '');

        expect(ShiftReconciliation::query()->firstOrFail()->variance_minor)->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
