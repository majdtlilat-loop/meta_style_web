<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
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
}
