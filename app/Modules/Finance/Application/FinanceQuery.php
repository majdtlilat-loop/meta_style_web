<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Finance\Domain\Enums\EntrySource;
use App\Modules\Finance\Domain\Enums\ExpenseStatus;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads the ledger, expenses and categories for staff. Bounded, permission-
 * checked and branch-scoped, and — like all history — readable without
 * `finance` (docs/20-FINANCE.md §3).
 */
final class FinanceQuery
{
    public const MAX_LIST = 100;

    public const PER_PAGE = 25;

    public function __construct(private readonly FinanceAccess $access) {}

    /**
     * @return list<FinanceEntry>
     *
     * @throws AuthorizationException
     * @throws FinanceFailed
     */
    public function ledger(User $user, string $branchUuid, string $fromDate, string $untilDate): array
    {
        $branch = $this->branch($branchUuid);

        $this->access->authorize($user, Permission::FinanceView, $branch->id, 'You may not view the ledger.');

        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        /** @var list<FinanceEntry> $entries */
        $entries = FinanceEntry::query()
            ->where('branch_id', $branch->id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LIST)
            ->get()
            ->all();

        return $entries;
    }

    /**
     * @return list<Expense>
     *
     * @throws AuthorizationException
     * @throws FinanceFailed
     */
    public function expenses(User $user, string $branchUuid, string $fromDate, string $untilDate): array
    {
        $branch = $this->branch($branchUuid);

        $this->access->authorize($user, Permission::ExpenseManage, $branch->id, 'You may not view expenses.');

        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        /** @var list<Expense> $expenses */
        $expenses = Expense::query()
            ->where('branch_id', $branch->id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->with('category')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LIST)
            ->get()
            ->all();

        return $expenses;
    }

    /**
     * A branch's expenses over whole branch-local days, newest first, narrowed
     * by category, method and state — one page at a time.
     *
     * @param  array{category?: string|null, method?: string|null, status?: string|null}  $filters
     * @return LengthAwarePaginator<int, Expense>
     *
     * @throws AuthorizationException
     * @throws FinanceFailed
     */
    public function expensesPage(User $user, string $branchUuid, string $fromDate, string $untilDate, array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $branch = $this->branch($branchUuid);

        $this->access->authorize($user, Permission::ExpenseManage, $branch->id, 'You may not view expenses.');

        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        // expenses(branch_id, occurred_at)
        $query = Expense::query()
            ->where('branch_id', $branch->id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->with('category')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $this->filterExpenses($query, $filters);

        return $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * What the filtered expenses add up to — posted, per currency, and the
     * posted total per category — summed HERE, so a screen only formats. A
     * voided expense is counted apart and never in the total.
     *
     * @param  array{category?: string|null, method?: string|null, status?: string|null}  $filters
     * @return array{posted: list<array{currency: string, total_minor: int, count: int}>, voided_count: int, by_category: list<array{category: string, name: string, currency: string, total_minor: int, count: int}>}
     *
     * @throws AuthorizationException
     * @throws FinanceFailed
     */
    public function expenseTotals(User $user, string $branchUuid, string $fromDate, string $untilDate, array $filters = []): array
    {
        $branch = $this->branch($branchUuid);

        $this->access->authorize($user, Permission::ExpenseManage, $branch->id, 'You may not view expenses.');

        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        $query = Expense::query()
            ->where('branch_id', $branch->id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until);

        $this->filterExpenses($query, $filters);

        $rows = $query->toBase()
            ->groupBy('status', 'currency', 'expense_category_id')
            ->selectRaw('status, currency, expense_category_id, SUM(amount_minor) AS total, COUNT(*) AS expenses')
            ->get()
            ->all();

        $categoryIds = [];

        foreach ($rows as $row) {
            /** @var object{status: string, currency: string, expense_category_id: int|string, total: int|string|null, expenses: int|string} $row */
            $categoryIds[(int) $row->expense_category_id] = true;
        }

        /** @var array<int, ExpenseCategory> $categories */
        $categories = $categoryIds === [] ? [] : ExpenseCategory::query()->whereIn('id', array_keys($categoryIds))->get()->keyBy('id')->all();
        $locale = app()->getLocale();

        $posted = [];
        $byCategory = [];
        $voided = 0;

        foreach ($rows as $row) {
            /** @var object{status: string, currency: string, expense_category_id: int|string, total: int|string|null, expenses: int|string} $row */
            if ($row->status === ExpenseStatus::Voided->value) {
                $voided += (int) $row->expenses;

                continue;
            }

            $currency = (string) $row->currency;
            $posted[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $posted[$currency]['total_minor'] += (int) $row->total;
            $posted[$currency]['count'] += (int) $row->expenses;

            $category = $categories[(int) $row->expense_category_id] ?? null;
            $byCategory[] = [
                'category' => $category === null ? '' : $category->uuid,
                'name' => $category === null ? '—' : $category->name->get($locale),
                'currency' => $currency,
                'total_minor' => (int) $row->total,
                'count' => (int) $row->expenses,
            ];
        }

        usort($byCategory, static fn (array $a, array $b): int => $b['total_minor'] <=> $a['total_minor']);

        return ['posted' => array_values($posted), 'voided_count' => $voided, 'by_category' => $byCategory];
    }

    /**
     * @param  Builder<Expense>  $query
     * @param  array{category?: string|null, method?: string|null, status?: string|null}  $filters
     */
    private function filterExpenses(Builder $query, array $filters): void
    {
        $category = trim((string) ($filters['category'] ?? ''));

        if ($category !== '') {
            $query->whereHas('category', fn (Builder $match) => $match->where('uuid', $category));
        }

        $method = PaymentMethod::tryFrom((string) ($filters['method'] ?? ''));

        if ($method !== null) {
            $query->where('method', $method->value);
        }

        $status = ExpenseStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status !== null) {
            $query->where('status', $status->value);
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function expense(string $uuid, User $user): Expense
    {
        /** @var Expense|null $expense */
        $expense = Expense::query()->where('uuid', $uuid)->with('category')->first();

        if (! $expense instanceof Expense) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::ExpenseManage, $expense->branch_id, 'You may not view expenses.');

        return $expense;
    }

    /**
     * The center's expense categories — for someone who records expenses.
     * Center-wide, so no branch narrows it; the permission still does.
     *
     * @return list<ExpenseCategory>
     *
     * @throws AuthorizationException
     */
    public function categories(User $user, bool $includeArchived = false): array
    {
        $this->access->authorize($user, Permission::ExpenseManage, 0, 'You may not manage expense categories.');

        $query = ExpenseCategory::query()->orderBy('sort_order')->orderBy('id');

        if (! $includeArchived) {
            $query->active();
        }

        /** @var list<ExpenseCategory> $categories */
        $categories = $query->limit(200)->get()->all();

        return $categories;
    }

    /**
     * @throws AuthorizationException
     */
    public function category(string $uuid, User $user): ExpenseCategory
    {
        /** @var ExpenseCategory|null $category */
        $category = ExpenseCategory::query()->where('uuid', $uuid)->first();

        if (! $category instanceof ExpenseCategory) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::ExpenseManage, 0, 'You may not manage expense categories.');

        return $category;
    }

    /**
     * The shift a close-with-count acts on: your own, or any at a branch you
     * may supervise.
     *
     * @throws AuthorizationException
     */
    public function shift(string $uuid, User $user): CashierShift
    {
        /** @var CashierShift|null $shift */
        $shift = CashierShift::query()->where('uuid', $uuid)->first();

        if (! $shift instanceof CashierShift) {
            throw new NotFoundHttpException;
        }

        $permission = $shift->user_id === $user->getKey() ? Permission::CashierShiftManage : Permission::CashierShiftSupervise;

        $this->access->authorize($user, $permission, $shift->branch_id, 'You may not view that shift.');

        return $shift;
    }

    /**
     * What each ledger entry is the money movement OF, in words a person can
     * act on — the invoice number of a payment or refund, the category and
     * description of an expense — resolved from the entries' own sources.
     *
     * The stored `label` is a snapshot in the language of whoever caused the
     * entry; this is read in the viewer's. Three queries whatever the number of
     * entries, for entries the caller has already been allowed to read.
     *
     * @param  list<FinanceEntry>  $entries
     * @return array<string, array{reference: string|null, detail: string|null}> keyed by entry uuid
     */
    public function references(array $entries): array
    {
        $bySource = [];

        foreach ($entries as $entry) {
            $bySource[$entry->source_type->value][] = $entry->source_uuid;
        }

        $locale = app()->getLocale();
        $resolved = [];

        if (($bySource[EntrySource::Payment->value] ?? []) !== []) {
            foreach (Payment::query()->whereIn('uuid', $bySource[EntrySource::Payment->value])->with('invoice')->get() as $payment) {
                /** @var Payment $payment */
                $resolved[EntrySource::Payment->value][$payment->uuid] = ['reference' => $payment->invoice?->number, 'detail' => $payment->manual_method_label];
            }
        }

        if (($bySource[EntrySource::Refund->value] ?? []) !== []) {
            foreach (Refund::query()->whereIn('uuid', $bySource[EntrySource::Refund->value])->with('payment.invoice')->get() as $refund) {
                /** @var Refund $refund */
                $resolved[EntrySource::Refund->value][$refund->uuid] = ['reference' => $refund->payment?->invoice?->number, 'detail' => $refund->reason];
            }
        }

        if (($bySource[EntrySource::Expense->value] ?? []) !== []) {
            foreach (Expense::query()->whereIn('uuid', $bySource[EntrySource::Expense->value])->with('category')->get() as $expense) {
                /** @var Expense $expense */
                $resolved[EntrySource::Expense->value][$expense->uuid] = ['reference' => $expense->category?->name->get($locale), 'detail' => $expense->description];
            }
        }

        $references = [];

        foreach ($entries as $entry) {
            $references[$entry->uuid] = $resolved[$entry->source_type->value][$entry->source_uuid] ?? ['reference' => null, 'detail' => null];
        }

        return $references;
    }

    /**
     * The drawer counts of shifts the caller has already been allowed to see —
     * one query, keyed by shift id. A shift closed without Finance has none.
     *
     * @param  list<CashierShift>  $shifts
     * @return array<int, ShiftReconciliation>
     */
    public function reconciliationsFor(array $shifts): array
    {
        $ids = array_map(static fn (CashierShift $shift): int => (int) $shift->getKey(), $shifts);

        if ($ids === []) {
            return [];
        }

        /** @var array<int, ShiftReconciliation> $counts */
        $counts = ShiftReconciliation::query()
            ->whereIn('cashier_shift_id', $ids)
            ->get()
            ->keyBy('cashier_shift_id')
            ->all();

        return $counts;
    }

    /**
     * Whether the center has any finance history — a posted expense or a
     * ledger entry — so a downgraded page knows there is something to keep
     * reading. Two indexed existence checks.
     */
    public function hasHistory(): bool
    {
        return FinanceEntry::query()->exists() || Expense::query()->exists();
    }

    private function branch(string $uuid): Branch
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $uuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        return $branch;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws FinanceFailed
     */
    private function window(Branch $branch, string $fromDate, string $untilDate): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilDate) !== 1) {
            throw FinanceFailed::policy('Dates are YYYY-MM-DD.');
        }

        $first = CarbonImmutable::parse($fromDate, 'UTC');
        $last = CarbonImmutable::parse($untilDate, 'UTC');

        if ($last->lessThan($first) || $first->diffInDays($last) + 1 > FinanceDashboard::MAX_DAYS) {
            throw FinanceFailed::policy('Choose a range of at most '.FinanceDashboard::MAX_DAYS.' days.');
        }

        $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';

        return [
            BranchClock::toUtcOrShift($fromDate, 0, $timezone),
            BranchClock::toUtcOrShift($last->addDay()->format('Y-m-d'), 0, $timezone),
        ];
    }
}
