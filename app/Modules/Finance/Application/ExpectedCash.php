<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Support\Facades\DB;

/**
 * What a drawer should hold, from the ledger.
 *
 *     expected = opening cash
 *              + CASH collections recorded against this shift
 *              − CASH refunds recorded against this shift
 *              − CASH expenses paid from this drawer
 *              + reversals of those expenses, while the shift was open
 *
 * Only `method = cash` counts. A card terminal, a transfer or an online payment
 * never passed through the drawer, so none of them changes what should be in it
 * (docs/20-FINANCE.md §32).
 *
 * One query, on the ledger's `cashier_shift_id` index. Computed
 * authoritatively at close, under the shift lock, and then SNAPSHOTTED — this
 * class is never used to re-derive a closed shift's figures.
 */
final class ExpectedCash
{
    /**
     * @return array{opening: int, collected: int, refunded: int, expenses: int, reversals: int, expected: int}
     */
    public function forShift(CashierShift $shift): array
    {
        /** @var array<string, int> $sums */
        $sums = DB::connection('tenant')->table('finance_entries')
            ->where('cashier_shift_id', $shift->getKey())
            ->where('method', PaymentMethod::Cash->value)
            ->selectRaw('kind, SUM(amount_minor) AS total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $opening = (int) ($shift->opening_cash_minor ?? 0);
        $collected = $sums[EntryKind::Collection->value] ?? 0;
        $refunded = $sums[EntryKind::Refund->value] ?? 0;
        $expenses = $sums[EntryKind::Expense->value] ?? 0;
        $reversals = $sums[EntryKind::ExpenseReversal->value] ?? 0;

        return [
            'opening' => $opening,
            'collected' => $collected,
            'refunded' => $refunded,
            'expenses' => $expenses,
            'reversals' => $reversals,
            'expected' => $opening + $collected - $refunded - $expenses + $reversals,
        ];
    }
}
