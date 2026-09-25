<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads sales for staff surfaces — bounded, eager-loaded, branch-scoped.
 *
 * Every list is capped and every relation a presenter touches is loaded here,
 * in a fixed number of queries whatever the number of sales or lines. A day's
 * sales list must cost the same at 5 sales as at 500 (docs/18-SALES.md §53).
 *
 * Reads need the permission and the branch, NOT `pos`: sales and invoices a
 * center already issued stay readable after a downgrade. The Actions a screen
 * calls next still require `pos` themselves ({@see SalesAccess}).
 */
final class SalesQuery
{
    public const MAX_LIST = 100;

    /** A history window is a working period, not a report: at most this many days. */
    public const MAX_DAYS = 92;

    public const PER_PAGE = 25;

    public function __construct(private readonly SalesAccess $access) {}

    /**
     * @throws AuthorizationException
     */
    public function find(string $uuid, User $user): Sale
    {
        /** @var Sale|null $sale */
        $sale = Sale::query()
            ->where('uuid', $uuid)
            ->with([
                'branch',
                'customer',
                'journey',
                'invoice.activeLink',
                'items.addons',
                'adjustments',
            ])
            ->first();

        if (! $sale instanceof Sale) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::SaleView, $sale->branch_id, 'You may not view sales.');

        return $sale;
    }

    /**
     * Issued sales for one branch-local day, newest first — or open drafts.
     *
     * @return list<Sale>
     *
     * @throws AuthorizationException
     * @throws SaleFailed
     */
    public function forBranch(User $user, string $branchUuid, ?string $status = null, ?string $date = null, int $limit = 50): array
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::SaleView, $branch->id, 'You may not view sales.');

        $limit = max(1, min(self::MAX_LIST, $limit));

        $query = Sale::query()
            ->where('branch_id', $branch->id)
            ->with(['customer', 'invoice']);

        if ($status === SaleStatus::Draft->value) {
            // sales(branch_id, status)
            $query->where('status', SaleStatus::Draft->value)->orderByDesc('id');
        } else {
            // sales(branch_id, finalized_at) — a half-open branch-local day.
            $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';

            // A typed or tampered `?date=` is refused, never handed to Carbon.
            if ($date !== null && ! self::isDate($date)) {
                throw SaleFailed::policy('Dates are YYYY-MM-DD.');
            }

            $day = $date ?? BranchClock::localDate(CarbonImmutable::now()->utc(), $timezone);

            $from = BranchClock::toUtcOrShift($day, 0, $timezone);
            $until = BranchClock::toUtcOrShift(CarbonImmutable::parse($day)->addDay()->format('Y-m-d'), 0, $timezone);

            $query->where('finalized_at', '>=', $from)
                ->where('finalized_at', '<', $until)
                ->orderByDesc('finalized_at')
                ->orderByDesc('id');

            if ($status === SaleStatus::Voided->value || $status === SaleStatus::Finalized->value) {
                $query->where('status', $status);
            }
        }

        /** @var list<Sale> $sales */
        $sales = $query->limit($limit)->get()->all();

        return $sales;
    }

    /**
     * Whether the center ever issued a sale — the difference between history
     * to keep reading after a downgrade and nothing to read at all. One
     * indexed existence check; reads nothing else.
     */
    public function hasHistory(): bool
    {
        return Sale::query()->where('status', '!=', SaleStatus::Draft->value)->exists();
    }

    /**
     * @return array{0: Invoice, 1: Sale}
     *
     * @throws AuthorizationException
     */
    public function invoice(string $uuid, User $user): array
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->where('uuid', $uuid)->with(['items', 'activeLink'])->first();

        if (! $invoice instanceof Invoice) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::SaleView, $invoice->branch_id, 'You may not view invoices.');

        /** @var Sale $sale */
        $sale = Sale::query()->whereKey($invoice->sale_id)->firstOrFail();

        return [$invoice, $sale];
    }

    /**
     * A shift's sales at a glance: how many, and how much per currency. Not a
     * report and not a reconciliation — the number a cashier glances at before
     * closing (docs/18-SALES.md §45).
     *
     * @return array{sales: int, voided: int, totals: list<array{currency: string, grand_total_minor: int}>}
     */
    public function shiftSummary(CashierShift $shift): array
    {
        /** @var list<object{status: string, currency: string, sales: int, total: int|string|null}> $rows */
        $rows = Sale::query()
            ->where('cashier_shift_id', $shift->getKey())
            ->selectRaw('status, currency, COUNT(*) AS sales, SUM(grand_total_minor) AS total')
            ->groupBy('status', 'currency')
            ->toBase()
            ->get()
            ->all();

        $sales = 0;
        $voided = 0;
        $totals = [];

        foreach ($rows as $row) {
            if ($row->status === SaleStatus::Voided->value) {
                $voided += (int) $row->sales;

                continue;
            }

            $sales += (int) $row->sales;
            $totals[] = ['currency' => $row->currency, 'grand_total_minor' => (int) $row->total];
        }

        return ['sales' => $sales, 'voided' => $voided, 'totals' => $totals];
    }

    /**
     * A branch's sales history over whole branch-local days, newest first —
     * issued and voided sales by when they were issued, or the open drafts.
     *
     * Search matches an invoice number (in full or in part) or the customer's
     * name; a cashier narrows to who issued the sale. Paginated; a page costs
     * the same few queries at 5 sales as at 5 000 (docs/18-SALES.md §53).
     *
     * @param  array{status?: string|null, term?: string|null, cashier?: string|null}  $filters
     * @return LengthAwarePaginator<int, Sale>
     *
     * @throws AuthorizationException
     * @throws SaleFailed
     */
    public function history(User $user, string $branchUuid, string $fromDate, string $untilDate, array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $branch = $this->readableBranch($branchUuid, $user);
        $status = (string) ($filters['status'] ?? '');

        $query = Sale::query()
            ->where('branch_id', $branch->id)
            ->with(['customer', 'invoice.activeLink']);

        if ($status === SaleStatus::Draft->value) {
            // A draft is a cart that is open NOW; it has no issue date.
            // sales(branch_id, status)
            $query->where('status', SaleStatus::Draft->value)->orderByDesc('id');
        } else {
            [$from, $until] = $this->window($branch, $fromDate, $untilDate);

            // sales(branch_id, finalized_at)
            $query->where('finalized_at', '>=', $from)
                ->where('finalized_at', '<', $until)
                ->orderByDesc('finalized_at')
                ->orderByDesc('id');

            if ($status === SaleStatus::Voided->value || $status === SaleStatus::Finalized->value) {
                $query->where('status', $status);
            }
        }

        $term = trim((string) ($filters['term'] ?? ''));

        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';

            $query->where(function (Builder $match) use ($like): void {
                $match->whereHas('invoice', fn (Builder $invoice) => $invoice->where('number', 'like', $like))
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like));
            });
        }

        $cashier = trim((string) ($filters['cashier'] ?? ''));

        if ($cashier !== '') {
            $query->where('finalized_by_id', $cashier);
        }

        return $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * Issued and voided sales in the window, counted and summed per currency —
     * the strip above the history. One grouped query on the issue-date index.
     *
     * @return list<array{status: string, currency: string, sales: int, total_minor: int}>
     *
     * @throws AuthorizationException
     * @throws SaleFailed
     */
    public function historyTotals(User $user, string $branchUuid, string $fromDate, string $untilDate): array
    {
        $branch = $this->readableBranch($branchUuid, $user);
        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        $rows = Sale::query()->toBase()
            ->where('branch_id', $branch->id)
            ->where('finalized_at', '>=', $from)
            ->where('finalized_at', '<', $until)
            ->groupBy('status', 'currency')
            ->selectRaw('status, currency, COUNT(*) AS sales, SUM(grand_total_minor) AS total')
            ->get()
            ->all();

        $totals = [];

        foreach ($rows as $row) {
            /** @var object{status: string, currency: string, sales: int|string, total: int|string|null} $row */
            $totals[] = [
                'status' => (string) $row->status,
                'currency' => (string) $row->currency,
                'sales' => (int) $row->sales,
                'total_minor' => (int) $row->total,
            ];
        }

        return $totals;
    }

    /**
     * Who issued sales at the branch in the window — the cashier filter.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws AuthorizationException
     * @throws SaleFailed
     */
    public function cashiers(User $user, string $branchUuid, string $fromDate, string $untilDate): array
    {
        $branch = $this->readableBranch($branchUuid, $user);
        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        $rows = Sale::query()->toBase()
            ->where('branch_id', $branch->id)
            ->where('finalized_at', '>=', $from)
            ->where('finalized_at', '<', $until)
            ->whereNotNull('finalized_by_id')
            ->groupBy('finalized_by_id')
            ->selectRaw('finalized_by_id, MAX(finalized_by_label) AS label')
            ->orderBy('label')
            ->limit(50)
            ->get()
            ->all();

        $cashiers = [];

        foreach ($rows as $row) {
            /** @var object{finalized_by_id: string, label: string|null} $row */
            $cashiers[] = ['id' => (string) $row->finalized_by_id, 'name' => (string) ($row->label ?? '—')];
        }

        return $cashiers;
    }

    /**
     * A branch's cashier shifts: every one still open, and those opened in the
     * window — all of them for a supervisor, only your own otherwise.
     *
     * @return list<CashierShift>
     *
     * @throws AuthorizationException
     * @throws SaleFailed
     */
    public function shifts(User $user, string $branchUuid, string $fromDate, string $untilDate): array
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $supervises = $user->hasPermission(Permission::CashierShiftSupervise);
        $permission = $supervises ? Permission::CashierShiftSupervise : Permission::CashierShiftManage;

        $this->access->authorize($user, $permission, $branch->id, 'You may not view cashier shifts.');

        [$from, $until] = $this->window($branch, $fromDate, $untilDate);

        $scope = function (Builder $query) use ($supervises, $user): void {
            if (! $supervises) {
                $query->where('user_id', $user->getKey());
            }
        };

        // cashier_shifts(active_user_id, branch_id) is unique per open shift;
        // the branch's open ones are few.
        $open = CashierShift::query()
            ->where('branch_id', $branch->id)
            ->whereNotNull('active_user_id')
            ->tap($scope)
            ->with('user')
            ->orderByDesc('opened_at')
            ->limit(self::MAX_LIST)
            ->get();

        // cashier_shifts(branch_id, opened_at)
        $recent = CashierShift::query()
            ->where('branch_id', $branch->id)
            ->where('opened_at', '>=', $from)
            ->where('opened_at', '<', $until)
            ->tap($scope)
            ->with('user')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LIST)
            ->get();

        /** @var list<CashierShift> $shifts */
        $shifts = $open->concat($recent)->unique('id')->values()->all();

        return $shifts;
    }

    /**
     * Each shift's issued sales, counted and summed per currency — one grouped
     * query for the whole list.
     *
     * @param  list<CashierShift>  $shifts
     * @return array<int, array{sales: int, voided: int, totals: list<array{currency: string, grand_total_minor: int}>}> keyed by shift id
     */
    public function shiftSummaries(array $shifts): array
    {
        $ids = array_map(static fn (CashierShift $shift): int => (int) $shift->getKey(), $shifts);

        if ($ids === []) {
            return [];
        }

        $rows = Sale::query()->toBase()
            ->whereIn('cashier_shift_id', $ids)
            ->groupBy('cashier_shift_id', 'status', 'currency')
            ->selectRaw('cashier_shift_id, status, currency, COUNT(*) AS sales, SUM(grand_total_minor) AS total')
            ->get()
            ->all();

        $summaries = array_fill_keys($ids, ['sales' => 0, 'voided' => 0, 'totals' => []]);

        foreach ($rows as $row) {
            /** @var object{cashier_shift_id: int|string, status: string, currency: string, sales: int|string, total: int|string|null} $row */
            $id = (int) $row->cashier_shift_id;

            if ($row->status === SaleStatus::Voided->value) {
                $summaries[$id]['voided'] += (int) $row->sales;

                continue;
            }

            $summaries[$id]['sales'] += (int) $row->sales;
            $summaries[$id]['totals'][] = ['currency' => (string) $row->currency, 'grand_total_minor' => (int) $row->total];
        }

        return $summaries;
    }

    /**
     * @throws AuthorizationException
     */
    private function readableBranch(string $branchUuid, User $user): Branch
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $this->access->authorize($user, Permission::SaleView, $branch->id, 'You may not view sales.');

        return $branch;
    }

    /**
     * A half-open window of whole branch-local days, bounded.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws SaleFailed
     */
    private function window(Branch $branch, string $fromDate, string $untilDate): array
    {
        if (! self::isDate($fromDate) || ! self::isDate($untilDate)) {
            throw SaleFailed::policy('Dates are YYYY-MM-DD.');
        }

        $first = CarbonImmutable::parse($fromDate, 'UTC');
        $last = CarbonImmutable::parse($untilDate, 'UTC');

        if ($last->lessThan($first)) {
            throw SaleFailed::policy('The range ends before it starts.');
        }

        if ($first->diffInDays($last) + 1 > self::MAX_DAYS) {
            throw SaleFailed::policy('Choose a range of at most '.self::MAX_DAYS.' days.');
        }

        $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';

        return [
            BranchClock::toUtcOrShift($fromDate, 0, $timezone),
            BranchClock::toUtcOrShift($last->addDay()->format('Y-m-d'), 0, $timezone),
        ];
    }

    /**
     * A real calendar date, `Y-m-d` — never handed to Carbon unchecked.
     */
    private static function isDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
