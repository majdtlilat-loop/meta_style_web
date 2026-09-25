<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Finance\Application\Actions\CloseShiftWithCount;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\FinanceDashboard;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Finance tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/20-FINANCE.md §64.
|
| One center's ledger, expenses and drawer counts never reach another's
| dashboard — even when every row id collides — and finance fails closed
| without a bound center.
|
*/

/**
 * A day of money in one center: a cash collection, a drawer expense, a count.
 *
 * @return array{entry: FinanceEntry, expense: Expense}
 */
function fiDay(int $amount): array
{
    test()->grantFinance();

    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();
    $shift = test()->openShift($seed['branch'], $owner);

    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    $invoice = app(FinalizeSale::class)($sale, $owner)->invoice;

    app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, $amount);

    $category = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
    $expense = app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 1000, 'method' => 'cash', 'description' => 'Towels', 'from_drawer' => true], $owner);

    app(CloseShiftWithCount::class)($shift->fresh() ?? $shift, $owner, $amount - 1000);

    return ['entry' => FinanceEntry::query()->orderBy('id')->firstOrFail(), 'expense' => $expense];
}

it('keeps two centers\' ledgers, expenses and counts apart when their ids collide', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => fiDay(20000));
    $b = $this->asCenter($beta['tenant'], fn (): array => fiDay(15000));

    expect($a['entry']->id)->toBe($b['entry']->id)
        ->and($a['expense']->id)->toBe($b['expense']->id)
        ->and($a['entry']->source_uuid)->not->toBe($b['entry']->source_uuid);

    // Each center's day, where its branch is.
    $summary = fn (): array => app(FinanceDashboard::class)->summary($this->ownerWithCatalogAccess(), $this->branchToday(), $this->branchToday());

    $alphaSummary = $this->asCenter($alpha['tenant'], $summary);
    $betaSummary = $this->asCenter($beta['tenant'], $summary);

    expect($alphaSummary['collected_minor'])->toBe(20000)
        ->and($betaSummary['collected_minor'])->toBe(15000)
        ->and($alphaSummary['invoiced_minor'])->toBe(20000)
        ->and($betaSummary['invoiced_minor'])->toBe(20000);

    $this->asCenter($beta['tenant'], function (): void {
        expect(FinanceEntry::query()->count())->toBe(2)
            ->and(Expense::query()->count())->toBe(1)
            ->and(ShiftReconciliation::query()->count())->toBe(1)
            ->and((int) ShiftReconciliation::query()->value('expected_cash_minor'))->toBe(14000);
    });
});

it('starts a new center with no categories, entries or counts of another', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], fn (): array => fiDay(20000));

    $this->asCenter($beta['tenant'], function (): void {
        expect(ExpenseCategory::query()->count())->toBe(0)
            ->and(Expense::query()->count())->toBe(0)
            ->and(FinanceEntry::query()->count())->toBe(0)
            ->and(ShiftReconciliation::query()->count())->toBe(0)
            ->and(CashierShift::query()->count())->toBe(0);
    });
});

it('fails closed when no center is bound, and leaves none bound after finance work', function (): void {
    $center = $this->registerCenter();

    expect(fn (): int => FinanceEntry::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => Expense::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => ExpenseCategory::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => ShiftReconciliation::query()->count())->toThrow(TenantConnectionNotInitialized::class);

    $this->asCenter($center['tenant'], fn (): array => fiDay(20000));

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('puts every finance table in the center database, with no tenant column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        foreach (['expense_categories', 'expenses', 'finance_entries', 'cashier_shift_reconciliations'] as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue()
                ->and(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))->toBeFalse();
        }
    });

    foreach (['expense_categories', 'expenses', 'cashier_shift_reconciliations'] as $table) {
        expect(Schema::connection('control')->hasTable($table))->toBeFalse("control has {$table}");
    }
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
