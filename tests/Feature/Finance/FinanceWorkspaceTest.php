<?php

declare(strict_types=1);

use App\Kernel\Localization\TenantLocales;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Expenses;
use App\Livewire\Center\FinanceOverview;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The finance workspace
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§38–44. Expenses found and totalled by branch-local
| day, category, method and state — totals added up by Finance, voided ones
| apart; a backdated expense lands on its own local day; categories renamed
| per content language without losing a switched-off one; the ledger read in
| the viewer's words; history readable after a downgrade.
|
*/

it('records a backdated expense, filters and totals expenses, and keeps voided ones apart', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter('Asia/Baghdad');
        $owner = $this->ownerWithCatalogAccess();
        $supplies = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
        $rent = app(ManageExpenseCategory::class)->save(['en' => 'Rent'], $owner);

        $today = $this->branchToday($seed['branch']);
        $yesterday = CarbonImmutable::parse($today)->subDay()->format('Y-m-d');
        $tomorrow = CarbonImmutable::parse($today)->addDay()->format('Y-m-d');

        $this->actingAs($owner, 'web');

        $screen = Livewire::test(Expenses::class)
            ->call('openForm')
            ->assertSet('occurredOn', $today)
            ->set('category', $supplies->uuid)
            ->set('amount', '4000')
            ->set('method', 'cash')
            ->set('description', 'Towels and gloves')
            ->set('occurredOn', $yesterday)
            ->call('post')
            ->assertSet('error', '')
            ->assertSet('showForm', false);

        $towels = Expense::query()->sole();

        // Local noon of the chosen Baghdad day.
        expect(BranchClock::localDate(CarbonImmutable::instance($towels->occurred_at), 'Asia/Baghdad'))->toBe($yesterday);

        $screen->call('openForm')
            ->set('category', $rent->uuid)
            ->set('amount', '250000')
            ->set('method', 'manual_electronic')
            ->set('description', 'September rent')
            ->set('occurredOn', $tomorrow)
            ->call('post')
            ->assertSet('error', 'An expense cannot be dated in the future.')
            ->set('occurredOn', $today)
            ->call('post')
            ->assertSet('error', '');

        $rentExpense = Expense::query()->where('description', 'September rent')->sole();
        app(RecordExpense::class)->void($towels, $owner, 'Posted twice');

        $totals = app(FinanceQuery::class)->expenseTotals($owner, $seed['branch']->uuid, $yesterday, $today);

        expect($totals['posted'])->toBe([['currency' => 'IQD', 'total_minor' => 250000, 'count' => 1]])
            ->and($totals['voided_count'])->toBe(1)
            ->and($totals['by_category'][0]['name'])->toBe('Rent');

        $query = app(FinanceQuery::class);

        expect($query->expensesPage($owner, $seed['branch']->uuid, $yesterday, $today, ['category' => $supplies->uuid])->total())->toBe(1)
            ->and($query->expensesPage($owner, $seed['branch']->uuid, $yesterday, $today, ['method' => 'manual_electronic'])->items()[0]->uuid)->toBe($rentExpense->uuid)
            ->and($query->expensesPage($owner, $seed['branch']->uuid, $yesterday, $today, ['status' => 'voided'])->total())->toBe(1);

        $screen->call('setRange', 'last_7_days')
            ->set('statusFilter', 'posted')
            ->assertSee('September rent')
            ->assertDontSee('Towels and gloves')
            ->assertViewHas('totals', fn (array $totals): bool => $totals['posted'] === '250,000 IQD' && $totals['voided_count'] === 0);
    });
});

it('renames a category per content language without losing a switched-off one, and brings an archived one back', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $category = app(ManageExpenseCategory::class)->save(['en' => 'Cleaning', 'ar' => 'تنظيف', 'ckb' => 'پاککردنەوە'], $owner);

        $this->actingAs($owner, 'web');

        Livewire::test(Expenses::class)
            ->call('editCategory', $category->uuid)
            ->assertSet('categoryNames', ['en' => 'Cleaning', 'ar' => 'تنظيف'])
            ->set('categoryNames.en', 'Cleaning supplies')
            ->call('saveCategory')
            ->assertSet('error', '')
            ->call('archiveCategory', $category->uuid)
            ->assertSet('error', '')
            ->call('restoreCategory', $category->uuid)
            ->assertSet('error', '');

        $fresh = ExpenseCategory::query()->sole();

        expect($fresh->name->all())->toBe(['en' => 'Cleaning supplies', 'ar' => 'تنظيف', 'ckb' => 'پاککردنەوە'])
            ->and($fresh->archived_at)->toBeNull()
            ->and($fresh->sort_order)->toBe($category->sort_order);
    });
});

it('reads the ledger in the viewer\'s words, and keeps it readable after Finance is withdrawn', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        $entries = FinanceEntry::query()->get()->all();
        $references = app(FinanceQuery::class)->references($entries);

        expect($references[$entries[0]->uuid]['reference'])->toBe($invoice->number);

        $this->actingAs($owner, 'web');

        Livewire::test(FinanceOverview::class)
            ->set('branch', $seed['branch']->uuid)
            ->assertSet('error', '')
            ->assertSee('Net money movement')
            ->assertViewHas('entries', fn (array $entries): bool => $entries[0]['kind_label'] === 'Payment received'
                && $entries[0]['reference'] === $invoice->number
                && $entries[0]['method_label'] === 'Cash');

        // A typed window that is too long is a message, not a crash.
        Livewire::withQueryParams(['range' => 'custom', 'from' => '2025-01-01', 'until' => '2025-12-31'])
            ->test(FinanceOverview::class)
            ->assertSet('error', 'Choose a range of at most 92 days.');

        // Finance withdrawn: the dashboard is refused, the ledger stays readable.
        $this->revokeEntitlement('finance');

        Livewire::withQueryParams([])->test(FinanceOverview::class)
            ->set('branch', $seed['branch']->uuid)
            ->assertDontSee('Net money movement')
            ->assertSee($invoice->number)
            ->assertViewHas('owned', false);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
