<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Enums\ExpenseStatus;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Expenses and their categories
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§38–41, 70.
|
| Center-defined categories, archived not deleted. An expense is posted and, if
| wrong, voided with a reason — a reversing entry, never an edit. "From the
| drawer" is said, not inferred. `finance` gates new work, not history.
|
*/

function exCategory($owner, string $name = 'Supplies'): ExpenseCategory
{
    return app(ManageExpenseCategory::class)->save(['en' => $name, 'ar' => $name], $owner);
}

function exPost(array $seed, $owner, ExpenseCategory $category, array $extra = []): Expense
{
    return app(RecordExpense::class)->post([
        'branch' => $seed['branch']->uuid,
        'category' => $category->uuid,
        'amount_minor' => 7500,
        'method' => 'cash',
        'description' => 'Hair wash supplies',
        ...$extra,
    ], $owner);
}

it('creates, renames and archives a category, and archived ones take no new expense', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $category = exCategory($owner, 'Supplis');
        $renamed = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner, $category);
        exPost($seed, $owner, $renamed);
        app(ManageExpenseCategory::class)->archive($renamed, $owner);

        expect($renamed->name->get('en'))->toBe('Supplies')
            ->and(ExpenseCategory::query()->count())->toBe(1)
            ->and(ExpenseCategory::query()->firstOrFail()->archived_at)->not->toBeNull()
            // Its past expense still points at it.
            ->and(Expense::query()->firstOrFail()->expense_category_id)->toBe($category->id);

        expect(fn () => exPost($seed, $owner, $renamed->fresh() ?? $renamed))
            ->toThrow(FinanceFailed::class, 'active expense category');

        expect(TenantAuditLog::query()->where('action', 'like', 'finance.expense_category.%')->count())->toBe(3);
    });
});

it('posts an expense with its ledger debit, and voids it with a reversal instead of deleting it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $expense = exPost($seed, $owner, exCategory($owner), ['method' => 'manual_electronic', 'reference' => 'TRX-1', 'payee' => 'Salon Supply Co']);

        expect($expense->status)->toBe(ExpenseStatus::Posted)
            ->and($expense->currency)->toBe('IQD')
            ->and(FinanceEntry::query()->where('kind', EntryKind::Expense->value)->value('amount_minor'))->toBe(7500);

        expect(fn () => app(RecordExpense::class)->void($expense, $owner, ''))
            ->toThrow(FinanceFailed::class, 'needs a reason');

        $voided = app(RecordExpense::class)->void($expense, $owner, 'Posted to the wrong branch');
        app(RecordExpense::class)->void($expense, $owner, 'Pressed twice');

        expect($voided->status)->toBe(ExpenseStatus::Voided)
            ->and($voided->void_reason)->toBe('Posted to the wrong branch')
            ->and(Expense::query()->count())->toBe(1)
            ->and(FinanceEntry::query()->where('kind', EntryKind::ExpenseReversal->value)->count())->toBe(1);
    });
});

it('links a cash expense to the drawer only when the poster says so, and only to their own open shift', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $category = exCategory($owner);

        expect(fn () => exPost($seed, $owner, $category, ['from_drawer' => true]))
            ->toThrow(FinanceFailed::class, 'open your cashier shift');

        $shift = $this->openShift($seed['branch'], $owner);

        $fromDrawer = exPost($seed, $owner, $category, ['from_drawer' => true]);
        $notFromDrawer = exPost($seed, $owner, $category);

        expect($fromDrawer->cashier_shift_id)->toBe($shift->id)
            // A shift being open does not make every expense a drawer expense.
            ->and($notFromDrawer->cashier_shift_id)->toBeNull();

        expect(fn () => exPost($seed, $owner, $category, ['from_drawer' => true, 'method' => 'manual_electronic']))
            ->toThrow(FinanceFailed::class, 'Only a cash expense');
    });
});

it('refuses another branch and another person\'s permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $category = exCategory($owner);
        $elsewhere = $this->seedBranch('Mansour');

        $clerk = $this->staffWith([Permission::ExpenseManage], 'clerk@alpha.test');
        $clerk->forceFill(['all_branches' => false])->save();
        $clerk->syncBranchScope([$seed['branch']->id]);
        $clerk->forgetPermissionCache();

        expect(fn () => app(RecordExpense::class)->post([
            'branch' => $elsewhere->uuid,
            'category' => $category->uuid,
            'amount_minor' => 1000,
            'method' => 'cash',
            'description' => 'Not my branch',
        ], $clerk->fresh() ?? $clerk))->toThrow(AuthorizationException::class);

        $cashier = $this->staffWith([Permission::PaymentCollect], 'cashier@alpha.test');

        expect(fn () => exPost($seed, $cashier, $category))->toThrow(AuthorizationException::class);

        expect(Expense::query()->count())->toBe(0);
    });
});

it('blocks new expenses without Finance, and keeps every past expense intact and readable', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $category = exCategory($owner);
        $expense = exPost($seed, $owner, $category);
        $row = Expense::query()->firstOrFail()->getAttributes();

        $this->revokeEntitlement('finance');

        expect(fn () => exPost($seed, $owner, $category))->toThrow(EntitlementRequired::class)
            ->and(fn () => app(RecordExpense::class)->void($expense, $owner, 'After the downgrade'))->toThrow(EntitlementRequired::class);

        $today = $this->branchToday($seed['branch']);

        expect(Expense::query()->firstOrFail()->getAttributes())->toBe($row)
            ->and(FinanceEntry::query()->count())->toBe(1)
            ->and(app(FinanceQuery::class)->expenses($owner, $seed['branch']->uuid, $today, $today))->toHaveCount(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
