<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\HasMoneyWindow;
use App\Livewire\Center\PosFinance\MoneyLabels;
use App\Livewire\Center\PosFinance\MoneyTabs;
use App\Livewire\Center\PosFinance\Refusals;
use App\Livewire\Center\Spending\ManagesExpenseCategories;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\FinancePresenter;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Expenses and their categories: post one, void one with a reason, find and
 * total them by branch-local days, category, method and state.
 *
 * "Paid from my drawer" is a switch the person turns on; it is never inferred
 * from whoever has a shift open (docs/20-FINANCE.md §41). Totals are added up
 * by `FinanceQuery`, per currency, never here. Posting and categories need
 * `finance`; expenses already posted stay readable after a downgrade.
 */
#[Layout('components.layouts.app')]
final class Expenses extends Component
{
    use GuardsMoneyActions;
    use HasMoneyWindow;
    use ManagesExpenseCategories;
    use RequiresFeature;
    use WithPagination;

    #[Url]
    public string $branch = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'method')]
    public string $methodFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    // ---- the expense being recorded ----------------------------------------

    public string $category = '';

    public string $amount = '';

    public string $method = 'cash';

    public string $description = '';

    public string $reference = '';

    public string $payee = '';

    public bool $fromDrawer = false;

    /** The branch-local day it was paid, `Y-m-d`; today when left alone. */
    public string $occurredOn = '';

    public string $voiding = '';

    public string $voidReason = '';

    #[Locked]
    public string $token = '';

    public string $error = '';

    public string $saved = '';

    public bool $showForm = false;

    public function mount(): void
    {
        if ($this->branch === '') {
            $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
            $this->user()->branchScope()->applyTo($query, 'id');
            $this->branch = (string) ($query->value('uuid') ?? '');
        }

        $this->token = (string) Str::uuid();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['branch', 'categoryFilter', 'methodFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['categoryFilter', 'methodFilter', 'statusFilter', 'range', 'from', 'until']);
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->reset(['error', 'saved']);
        $this->resetValidation();
        $this->occurredOn = BranchTime::today($this->windowTimezone());
        $this->showCategories = false;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function post(RecordExpense $record): void
    {
        $posted = $this->attempt(function () use ($record): void {
            try {
                $minor = Money::fromMajorString($this->amount, Currency::default())->minor;
            } catch (InvalidArgumentException) {
                throw FinanceFailed::policy('Enter an amount like 25000.');
            }

            $record->post([
                'branch' => $this->branch,
                'category' => $this->category,
                'amount_minor' => $minor,
                'method' => $this->method,
                'description' => $this->description,
                'occurred_at' => $this->occurredAt(),
                'reference' => $this->reference,
                'payee' => $this->payee,
                'from_drawer' => $this->method === 'cash' && $this->fromDrawer,
                'idempotency_token' => $this->token,
            ], $this->user());

            $this->reset(['amount', 'description', 'reference', 'payee', 'fromDrawer', 'occurredOn']);
            $this->token = (string) Str::uuid();
            $this->saved = (string) __('Expense posted.');
        });

        if ($posted) {
            $this->showForm = false;
        }
    }

    public function void(RecordExpense $record, FinanceQuery $query): void
    {
        $this->attempt(function () use ($record, $query): void {
            $record->void($query->expense($this->voiding, $this->user()), $this->user(), $this->voidReason);

            $this->reset(['voiding', 'voidReason']);
            $this->saved = (string) __('Expense voided. A reversing entry was recorded.');
        });
    }

    protected function defaultRange(): string
    {
        return 'this_month';
    }

    protected function windowTimezone(): string
    {
        return BranchTime::zoneOf(Branch::query()->where('uuid', $this->branch)->first());
    }

    public function render(FinanceQuery $query, FinancePresenter $presenter, Entitlements $entitlements, TenantLocales $locales, LanguageRegistry $languages): View
    {
        $owned = $entitlements->enabled('finance');

        if (! $owned && ! $query->hasHistory() && ($locked = $this->lockedView('finance'))) {
            return $locked;
        }

        $user = $this->user();
        $allowed = $user->hasPermission(Permission::ExpenseManage);

        $branchQuery = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $user->branchScope()->applyTo($branchQuery, 'id');
        /** @var list<Branch> $branches */
        $branches = $branchQuery->get()->all();

        $timezone = $this->windowTimezone();
        [$from, $until] = $this->windowDates($timezone);
        $filters = ['category' => $this->categoryFilter, 'method' => $this->methodFilter, 'status' => $this->statusFilter];

        $expenses = [];
        $paginator = null;
        $totals = null;
        $categories = [];
        $listError = '';

        try {
            $categories = array_map(fn (ExpenseCategory $category): array => $presenter->category($category), $query->categories($user, includeArchived: true));

            if ($this->branch !== '') {
                $paginator = $query->expensesPage($user, $this->branch, $from, $until, $filters, $this->getPage());
                $expenses = array_map(fn (Expense $expense): array => $this->row($presenter->expense($expense), $timezone), array_values($paginator->items()));
                $totals = $this->totals($query->expenseTotals($user, $this->branch, $from, $until, $filters));
            }
        } catch (FinanceFailed|AuthorizationException $failure) {
            $listError = Refusals::text($failure->getMessage());
            // The refusal is the page's state for someone without the permission.
            $this->error = $this->error === '' ? $listError : $this->error;
        } catch (NotFoundHttpException) {
            $listError = (string) __('That branch could not be found.');
        }

        $active = array_values(array_filter($categories, static fn (array $category): bool => ! $category['archived']));

        return view('livewire.center.expenses', [
            'tabs' => MoneyTabs::for($user, 'expenses'),
            'window' => $this->windowControl($timezone),
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'expenses' => $expenses,
            'paginator' => $paginator,
            'totals' => $totals,
            'categories' => $active,
            'allCategories' => $this->showArchivedCategories ? $categories : $active,
            'archivedCount' => count($categories) - count($active),
            'categoryLocales' => $this->categoryLocales($locales, $languages),
            'methods' => [['value' => 'cash', 'label' => MoneyLabels::method('cash')], ['value' => 'manual_electronic', 'label' => MoneyLabels::method('manual_electronic')]],
            'listError' => $listError,
            'denied' => ! $allowed,
            'owned' => $owned,
            'offer' => $owned ? null : $this->lockedFeature('finance'),
            'canRecord' => $allowed && $owned,
            'hasFilters' => $this->categoryFilter !== '' || $this->methodFilter !== '' || $this->statusFilter !== '',
            'today' => BranchTime::today($timezone),
        ]);
    }

    /**
     * The day it was paid, as an instant: now for today, local noon for an
     * earlier day — so it falls inside that branch-local day whatever the
     * timezone. A future day is the Action's to refuse.
     */
    private function occurredAt(): ?CarbonImmutable
    {
        $timezone = $this->windowTimezone();
        $day = trim($this->occurredOn);

        if ($day === '' || $day === BranchTime::today($timezone)) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw FinanceFailed::policy('Dates are YYYY-MM-DD.');
        }

        return BranchClock::toUtcOrShift($day, 12 * 60, $timezone);
    }

    /**
     * @param  array<string, mixed>  $expense
     * @return array<string, mixed>
     */
    private function row(array $expense, string $timezone): array
    {
        return $expense + [
            'when' => BranchTime::label($expense['occurred_at'], $timezone, BranchTime::DATE),
            'method_label' => MoneyLabels::method($expense['method']),
            'status_label' => (string) __('manager_finance.expense_status.'.$expense['status']),
            'posted' => $expense['status'] === 'posted',
            'voided_at_label' => BranchTime::label($expense['voided_at'] ?? null, $timezone, BranchTime::DATETIME),
        ];
    }

    /**
     * @param  array{posted: list<array{currency: string, total_minor: int, count: int}>, voided_count: int, by_category: list<array{category: string, name: string, currency: string, total_minor: int, count: int}>}  $totals
     * @return array<string, mixed>
     */
    private function totals(array $totals): array
    {
        return [
            'posted' => MoneyLabels::amounts($totals['posted']),
            'posted_count' => array_sum(array_column($totals['posted'], 'count')),
            'voided_count' => $totals['voided_count'],
            'mixed' => count($totals['posted']) > 1,
            'by_category' => array_map(static fn (array $row): array => [
                'category' => $row['category'],
                'name' => $row['name'],
                'amount' => MoneyLabels::amounts([$row]),
                'count' => $row['count'],
            ], array_slice($totals['by_category'], 0, 6)),
        ];
    }
}
