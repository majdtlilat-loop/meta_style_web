<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\Expenses;
use App\Livewire\Center\FinanceOverview;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Domain\Enums\ExpenseStatus;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Finance surfaces
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§57, 62.
|
| The API and the manager's screens call the same Actions. Categories, an
| expense and its void, the ledger and the dashboard over HTTP; the counted
| close; and the entitlement answer a client branches on.
|
*/

it('runs expenses, the ledger, the dashboard and a counted close through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    [$branch, $shift, $today] = $this->asCenter($center['tenant'], function (): array {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::Cash, 20000);

        return [$seed['branch'], CashierShift::query()->where('active_user_id', $owner->id)->firstOrFail(), $this->branchToday($seed['branch'])];
    });

    $category = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/finance/expense-categories', ['name' => ['en' => 'Supplys', 'ar' => 'مستلزمات']])
        ->assertStatus(201)
        ->json('data.category.uuid');

    $this->withHeaders($headers)
        ->putJson("/api/v1/tenant/finance/expense-categories/{$category}", ['name' => ['en' => 'Supplies', 'ar' => 'مستلزمات']])
        ->assertStatus(200)
        ->assertJsonPath('data.category.name', 'Supplies');

    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/finance/expenses', ['branch' => $branch->uuid, 'category' => $category, 'amount_minor' => 3000, 'method' => 'cash', 'description' => 'Coffee for the desk', 'from_drawer' => true])
        ->assertStatus(201)
        ->assertJsonPath('data.expense.from_drawer', true)
        ->assertJsonPath('data.expense.category.name', 'Supplies');

    $rent = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/finance/expenses', ['branch' => $branch->uuid, 'category' => $category, 'amount_minor' => 8000, 'method' => 'manual_electronic', 'description' => 'Entered twice'])
        ->assertStatus(201)
        ->json('data.expense.uuid');

    // A gateway is not a way to pay an expense; a policy refusal carries its code.
    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/finance/expenses', ['branch' => $branch->uuid, 'category' => $category, 'amount_minor' => 1000, 'method' => 'gateway', 'description' => 'Nope'])
        ->assertStatus(422);

    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/finance/expenses', ['branch' => $branch->uuid, 'category' => $category, 'amount_minor' => 1000, 'method' => 'manual_electronic', 'description' => 'From the drawer by transfer', 'from_drawer' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'FINANCE.POLICY_VIOLATION');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/finance/expenses/{$rent}/void", ['reason' => 'Posted twice by mistake'])
        ->assertStatus(200)
        ->assertJsonPath('data.expense.status', 'voided');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/finance/expenses?branch={$branch->uuid}&from={$today}&until={$today}")
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.expenses');

    $ledger = $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/finance/ledger?branch={$branch->uuid}&from={$today}&until={$today}")
        ->assertStatus(200);

    expect(collect($ledger->json('data.entries'))->pluck('kind')->sort()->values()->all())
        ->toBe(['collection', 'expense', 'expense', 'expense_reversal']);

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/finance/dashboard?from={$today}&until={$today}")
        ->assertStatus(200)
        ->assertJsonPath('data.summary.invoiced_minor', 20000)
        ->assertJsonPath('data.summary.collected_minor', 20000)
        ->assertJsonPath('data.summary.net_expenses_minor', 3000)
        ->assertJsonPath('data.summary.net_movement_minor', 17000);

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/cashier-shifts/{$shift->uuid}/expected-cash")
        ->assertStatus(200)
        ->assertJsonPath('data.expected.expected.amount', 17000);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/cashier-shifts/{$shift->uuid}/close-with-count", ['counted_cash_minor' => 17500, 'note' => 'Found a note in the tip jar'])
        ->assertStatus(200)
        ->assertJsonPath('data.reconciliation.expected_cash.amount', 17000)
        ->assertJsonPath('data.reconciliation.variance.amount', 500);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/cashier-shifts/{$shift->uuid}/close-with-count", ['counted_cash_minor' => 17500])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'FINANCE.INVALID_TRANSITION');

    $this->withHeaders($headers)
        ->deleteJson("/api/v1/tenant/finance/expense-categories/{$category}")
        ->assertStatus(200)
        ->assertJsonPath('data.category.archived', true);

    $this->asCenter($center['tenant'], function (): void {
        expect(ExpenseCategory::query()->count())->toBe(1)
            ->and(Expense::query()->count())->toBe(2)
            ->and(ShiftReconciliation::query()->count())->toBe(1);
    });
});

it('answers ENTITLEMENT.NOT_AVAILABLE for new finance work without Finance, and still lists the history', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    [$branch, $today] = $this->asCenter($center['tenant'], function (): array {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $category = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
        app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 2500, 'method' => 'cash', 'description' => 'Towels'], $owner);

        $this->revokeEntitlement('finance');

        return [$seed['branch'], $this->branchToday($seed['branch'])];
    });

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/finance/dashboard?from={$today}&until={$today}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ENTITLEMENT.NOT_AVAILABLE');

    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/finance/expense-categories', ['name' => ['en' => 'Rent']])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'ENTITLEMENT.NOT_AVAILABLE');

    // History is not deleted or hidden by a downgrade.
    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/finance/expenses?branch={$branch->uuid}&from={$today}&until={$today}")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.expenses');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/finance/ledger?branch={$branch->uuid}&from={$today}&until={$today}")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.entries');
});

it('shows no finance record — not even category names — to staff without the permission', function (): void {
    $center = $this->registerCenter();

    [$branch, $today, $shift, $clerk] = $this->asCenter($center['tenant'], function (): array {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        app(ManageExpenseCategory::class)->save(['en' => 'Owner bonus pool'], $owner);
        $shift = $this->openShift($seed['branch'], $owner);

        // A cashier-like account: sells and collects, but keeps no books.
        $clerk = $this->staffWith([Permission::SaleView, Permission::PaymentView, Permission::PaymentCollect, Permission::CashierShiftManage], 'clerk@alpha.test');

        return [$seed['branch'], $this->branchToday($seed['branch']), $shift, $clerk];
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant'], $clerk));

    $categories = $this->withHeaders($headers)->getJson('/api/v1/tenant/finance/expense-categories')->assertStatus(403);

    expect(str_contains((string) $categories->getContent(), 'Owner bonus pool'))->toBeFalse();

    $this->withHeaders($headers)->getJson("/api/v1/tenant/finance/ledger?branch={$branch->uuid}&from={$today}&until={$today}")->assertStatus(403);
    $this->withHeaders($headers)->getJson("/api/v1/tenant/finance/expenses?branch={$branch->uuid}&from={$today}&until={$today}")->assertStatus(403);
    $this->withHeaders($headers)->getJson("/api/v1/tenant/finance/dashboard?from={$today}&until={$today}")->assertStatus(403);

    // Someone else's drawer: its expected cash is a supervisor's to see.
    $this->withHeaders($headers)->getJson("/api/v1/tenant/cashier-shifts/{$shift->uuid}/expected-cash")->assertStatus(403);

    $this->asCenter($center['tenant'], function () use ($clerk): void {
        $this->actingAs($clerk, 'web');

        Livewire::test(Expenses::class)
            ->assertNotSet('error', '')
            ->assertDontSee('Owner bonus pool');
    });
});

it('posts and voids an expense from the expenses screen', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->actingAs($owner, 'web');

        $screen = Livewire::test(Expenses::class)
            ->set('categoryName', 'Cleaning')
            ->call('addCategory')
            ->assertSet('error', '');

        $category = ExpenseCategory::query()->firstOrFail();

        $screen->set('branch', $seed['branch']->uuid)
            ->set('category', $category->uuid)
            ->set('amount', '4500')
            ->set('method', 'manual_electronic')
            ->set('description', 'Floor cleaning service')
            ->call('post')
            ->assertSet('error', '')
            ->assertSee('Floor cleaning service');

        $expense = Expense::query()->firstOrFail();

        $screen->set('voiding', $expense->uuid)
            ->set('voidReason', 'Wrong month')
            ->call('void')
            ->assertSet('error', '');

        expect($expense->fresh()?->status)->toBe(ExpenseStatus::Voided)
            ->and(FinanceEntry::query()->count())->toBe(2);
    });
});

it('shows the finance page nothing but the refusal without Finance', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();
        $this->actingAs($this->ownerWithCatalogAccess(), 'web');

        Livewire::test(FinanceOverview::class)
            ->assertNotSet('error', '')
            ->assertDontSee('Net money movement');
    });
});

it('keeps every finance route behind authentication', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->getActionName(), 'FinanceController@')
            || str_contains((string) $route->getActionName(), 'Livewire\Center\FinanceOverview')
            || str_contains((string) $route->getActionName(), 'Livewire\Center\Expenses'));

    // 11 API routes on FinanceController, plus the two manager pages.
    expect($routes->count())->toBe(13);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain(str_starts_with($route->uri(), 'api/') ? 'auth:sanctum' : 'auth:web');
    }
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
