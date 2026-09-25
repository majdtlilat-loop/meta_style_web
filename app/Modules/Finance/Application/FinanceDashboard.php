<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Center finance over a bounded window: what was BILLED, what money MOVED.
 *
 * ## Five numbers that are not one number
 *
 *   invoiced         invoices issued, not voided          → from invoices
 *   collected        payments that succeeded              → from the ledger
 *   refunded         refunds that succeeded               → from the ledger
 *   expenses         expenses posted, net of reversals    → from the ledger
 *   net movement     collected − refunded − expenses      → money, not revenue
 *
 * An unpaid invoice is not collected money, and nothing here calls any total
 * "revenue" (docs/20-FINANCE.md §§42–44). Audit logs are never read: they
 * answer who did what, not how much.
 *
 * ## Bounded
 *
 * At most {@see MAX_DAYS} days, over the branches the viewer may see. A fixed
 * number of aggregate queries whatever the volume; a test pins it.
 *
 * Days are branch-local: with one branch selected, its timezone; across
 * branches, the main branch's.
 */
final class FinanceDashboard
{
    public const MAX_DAYS = 92;

    public function __construct(
        private readonly FinanceAccess $access,
        private readonly InvoiceSettlement $settlement,
    ) {}

    /**
     * @return array{
     *     from: string, until: string, branches: list<string>,
     *     invoiced_minor: int, invoice_count: int, voided_minor: int,
     *     collected_minor: int, collected_by_method: array<string, int>,
     *     refunded_minor: int, refunded_by_method: array<string, int>,
     *     expenses_minor: int, expense_reversals_minor: int, net_expenses_minor: int,
     *     net_movement_minor: int, outstanding_minor: int,
     *     by_branch: list<array{branch: string, name: string, invoiced_minor: int, collected_minor: int, refunded_minor: int, net_expenses_minor: int}>,
     *     variances: list<array{shift: string, branch: string, cashier: string|null, expected_minor: int, counted_minor: int, variance_minor: int, reconciled_at: string}>,
     *     variance_total_minor: int
     * }
     *
     * @throws FinanceFailed
     * @throws AuthorizationException
     */
    public function summary(User $viewer, string $fromDate, string $untilDate, ?string $branchUuid = null): array
    {
        $branches = $this->branches($viewer, $branchUuid);

        foreach ($branches as $branch) {
            $this->access->ensure($viewer, Permission::FinanceView, $branch->id, 'You may not view center finance.');
        }

        if ($branches === []) {
            throw new AuthorizationException('You may not view center finance.');
        }

        [$from, $until] = $this->window($fromDate, $untilDate, $branches);
        $ids = array_map(static fn (Branch $branch): int => $branch->id, $branches);

        // 1 — billed: invoices issued in the window, split by the sale's state.
        /** @var list<object{branch_id: int|string, status: string, total: int|string|null, invoices: int|string}> $invoiceRows */
        // Through the model, never `table('invoices')` (ADR-054).
        $invoiceRows = Invoice::query()->toBase()
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->whereIn('invoices.branch_id', $ids)
            ->where('invoices.issued_at', '>=', $from)
            ->where('invoices.issued_at', '<', $until)
            ->groupBy('invoices.branch_id', 'sales.status')
            ->selectRaw('invoices.branch_id AS branch_id, sales.status AS status, SUM(invoices.grand_total_minor) AS total, COUNT(*) AS invoices')
            ->get()
            ->all();

        // 2 — moved: every ledger entry in the window.
        /** @var list<object{branch_id: int|string, kind: string, method: string, total: int|string|null}> $ledgerRows */
        $ledgerRows = DB::connection('tenant')->table('finance_entries')
            ->whereIn('branch_id', $ids)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->groupBy('branch_id', 'kind', 'method')
            ->selectRaw('branch_id, kind, method, SUM(amount_minor) AS total')
            ->get()
            ->all();

        // 3 — still owed on what was billed in the window.
        $outstanding = $this->settlement->outstandingIssuedBetween($ids, $from, $until);

        // 4 — drawer counts closed in the window.
        /** @var list<object{shift_uuid: string, branch_id: int|string, cashier: string|null, expected_cash_minor: int|string, counted_cash_minor: int|string, variance_minor: int|string, reconciled_at: string}> $varianceRows */
        $varianceRows = DB::connection('tenant')->table('cashier_shift_reconciliations')
            ->join('cashier_shifts', 'cashier_shifts.id', '=', 'cashier_shift_reconciliations.cashier_shift_id')
            ->leftJoin('users', 'users.id', '=', 'cashier_shifts.user_id')
            ->whereIn('cashier_shift_reconciliations.branch_id', $ids)
            ->where('cashier_shift_reconciliations.reconciled_at', '>=', $from)
            ->where('cashier_shift_reconciliations.reconciled_at', '<', $until)
            ->orderByDesc('cashier_shift_reconciliations.reconciled_at')
            ->limit(50)
            ->select([
                'cashier_shifts.uuid AS shift_uuid',
                'cashier_shift_reconciliations.branch_id AS branch_id',
                'users.name AS cashier',
                'cashier_shift_reconciliations.expected_cash_minor',
                'cashier_shift_reconciliations.counted_cash_minor',
                'cashier_shift_reconciliations.variance_minor',
                'cashier_shift_reconciliations.reconciled_at',
            ])
            ->get()
            ->all();

        $names = [];
        $uuids = [];

        foreach ($branches as $branch) {
            $names[$branch->id] = $branch->name->get(app()->getLocale());
            $uuids[$branch->id] = $branch->uuid;
        }

        $byBranch = [];

        foreach ($ids as $id) {
            $byBranch[$id] = ['branch' => $uuids[$id], 'name' => $names[$id], 'invoiced_minor' => 0, 'collected_minor' => 0, 'refunded_minor' => 0, 'net_expenses_minor' => 0];
        }

        $invoiced = 0;
        $invoiceCount = 0;
        $voided = 0;

        foreach ($invoiceRows as $row) {
            if ($row->status === SaleStatus::Voided->value) {
                $voided += (int) $row->total;

                continue;
            }

            $invoiced += (int) $row->total;
            $invoiceCount += (int) $row->invoices;
            $byBranch[(int) $row->branch_id]['invoiced_minor'] += (int) $row->total;
        }

        $methods = array_fill_keys(array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()), 0);
        $collectedBy = $methods;
        $refundedBy = $methods;
        $expenses = 0;
        $reversals = 0;

        foreach ($ledgerRows as $row) {
            $amount = (int) $row->total;
            $branchId = (int) $row->branch_id;

            match ($row->kind) {
                EntryKind::Collection->value => $collectedBy[$row->method] = ($collectedBy[$row->method] ?? 0) + $amount,
                EntryKind::Refund->value => $refundedBy[$row->method] = ($refundedBy[$row->method] ?? 0) + $amount,
                EntryKind::Expense->value => $expenses += $amount,
                EntryKind::ExpenseReversal->value => $reversals += $amount,
                default => null,
            };

            match ($row->kind) {
                EntryKind::Collection->value => $byBranch[$branchId]['collected_minor'] += $amount,
                EntryKind::Refund->value => $byBranch[$branchId]['refunded_minor'] += $amount,
                EntryKind::Expense->value => $byBranch[$branchId]['net_expenses_minor'] += $amount,
                EntryKind::ExpenseReversal->value => $byBranch[$branchId]['net_expenses_minor'] -= $amount,
                default => null,
            };
        }

        $collected = array_sum($collectedBy);
        $refunded = array_sum($refundedBy);
        $netExpenses = $expenses - $reversals;

        $variances = array_map(fn (object $row): array => [
            'shift' => $row->shift_uuid,
            'branch' => $uuids[(int) $row->branch_id],
            'cashier' => $row->cashier,
            'expected_minor' => (int) $row->expected_cash_minor,
            'counted_minor' => (int) $row->counted_cash_minor,
            'variance_minor' => (int) $row->variance_minor,
            'reconciled_at' => CarbonImmutable::parse($row->reconciled_at, 'UTC')->toIso8601String(),
        ], $varianceRows);

        return [
            'from' => $from->toIso8601String(),
            'until' => $until->toIso8601String(),
            'branches' => array_values($uuids),
            'invoiced_minor' => $invoiced,
            'invoice_count' => $invoiceCount,
            'voided_minor' => $voided,
            'collected_minor' => $collected,
            'collected_by_method' => $collectedBy,
            'refunded_minor' => $refunded,
            'refunded_by_method' => $refundedBy,
            'expenses_minor' => $expenses,
            'expense_reversals_minor' => $reversals,
            'net_expenses_minor' => $netExpenses,
            'net_movement_minor' => $collected - $refunded - $netExpenses,
            'outstanding_minor' => $outstanding,
            'by_branch' => array_values($byBranch),
            'variances' => $variances,
            'variance_total_minor' => array_sum(array_column($variances, 'variance_minor')),
        ];
    }

    /**
     * @return list<Branch>
     *
     * @throws AuthorizationException
     */
    private function branches(User $viewer, ?string $branchUuid): array
    {
        $query = Branch::query()->orderByDesc('is_main')->orderBy('id');

        if ($branchUuid !== null && $branchUuid !== '') {
            $query->where('uuid', $branchUuid);
        }

        $viewer->branchScope()->applyTo($query, 'id');

        /** @var list<Branch> $branches */
        $branches = $query->get()->all();

        if ($branchUuid !== null && $branchUuid !== '' && $branches === []) {
            throw new AuthorizationException('You may not view that branch.');
        }

        return $branches;
    }

    /**
     * A half-open, branch-local window.
     *
     * @param  list<Branch>  $branches
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws FinanceFailed
     */
    private function window(string $fromDate, string $untilDate, array $branches): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilDate) !== 1) {
            throw FinanceFailed::policy('Dates are YYYY-MM-DD.');
        }

        $first = CarbonImmutable::parse($fromDate, 'UTC');
        $last = CarbonImmutable::parse($untilDate, 'UTC');

        if ($last->lessThan($first)) {
            throw FinanceFailed::policy('The range ends before it starts.');
        }

        if ($first->diffInDays($last) + 1 > self::MAX_DAYS) {
            throw FinanceFailed::policy('Choose a range of at most '.self::MAX_DAYS.' days.');
        }

        $timezone = $branches[0]->timezone !== '' ? $branches[0]->timezone : 'UTC';

        return [
            BranchClock::toUtcOrShift($fromDate, 0, $timezone),
            BranchClock::toUtcOrShift($last->addDay()->format('Y-m-d'), 0, $timezone),
        ];
    }
}
