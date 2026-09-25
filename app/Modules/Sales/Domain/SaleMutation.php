<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Pricing\AdjustmentInput;
use App\Modules\Sales\Domain\Pricing\LineInput;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use App\Modules\Sales\Domain\Pricing\SaleTotals;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single writer of a draft sale.
 *
 * Every change to a cart — a line added, a quantity changed, a discount applied,
 * a customer attached — runs through here, and here only:
 *
 *     BEGIN
 *       SELECT ... FROM sales WHERE id = ? FOR UPDATE    ← the lock
 *       refuse unless the LOCKED row is still a draft    ← never the stale copy
 *       apply the change
 *       recalculate every total from the stored lines    ← SalePricing, nothing else
 *     COMMIT
 *
 * Two tills editing one cart serialise on the row. A finalization that wins the
 * lock first leaves the loser looking at a finalized sale, which it refuses
 * rather than editing (docs/18-SALES.md §§18–19, 27).
 *
 * And because the recalculation runs inside the same transaction as every
 * change, a sale's stored totals can never describe a cart it does not have.
 */
final class SaleMutation
{
    public function __construct(private readonly SalePricing $pricing) {}

    /**
     * @template TResult
     *
     * @param  callable(Sale): TResult  $mutate  receives the LOCKED draft
     * @return array{0: Sale, 1: TResult}
     *
     * @throws SaleFailed
     */
    public function apply(Sale $sale, callable $mutate): array
    {
        /** @var array{0: Sale, 1: TResult} $outcome */
        $outcome = DB::connection('tenant')->transaction(function () use ($sale, $mutate): array {
            $locked = $this->lock($sale);

            if (! $locked->isDraft()) {
                throw SaleFailed::invalidTransition(
                    "That sale is {$locked->status->value} and can no longer be changed.",
                    ['status' => $locked->status->value],
                );
            }

            $result = $mutate($locked);

            $this->recalculate($locked);

            return [$locked, $result];
        });

        return $outcome;
    }

    /**
     * Locks the sale row for the rest of the current transaction and returns the
     * row AS IT IS NOW — which is the only copy any decision may be made from.
     *
     * @throws SaleFailed
     */
    public function lock(Sale $sale): Sale
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('SaleMutation::lock() must be called inside a transaction.');
        }

        /** @var Sale|null $locked */
        $locked = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Sale) {
            throw SaleFailed::invalidTransition('That sale no longer exists.');
        }

        return $locked;
    }

    /**
     * Re-prices a LOCKED sale from its stored lines and adjustments, and writes
     * the result. Never reads the catalog.
     *
     * @throws SaleFailed
     */
    public function recalculate(Sale $locked): SaleTotals
    {
        /** @var list<SaleItem> $items */
        $items = SaleItem::query()->where('sale_id', $locked->getKey())
            ->orderBy('position')->orderBy('id')->get()->all();

        /** @var list<SaleAdjustment> $adjustments */
        $adjustments = SaleAdjustment::query()->where('sale_id', $locked->getKey())
            ->orderBy('position')->orderBy('id')->get()->all();

        foreach ($items as $item) {
            if ($item->currency !== $locked->currency) {
                // One currency per sale, always. A line in another currency is
                // a bug somewhere upstream, and summing it would be a lie.
                throw SaleFailed::policy('Every line of a sale must be in the sale currency.', [
                    'sale_currency' => $locked->currency,
                    'line_currency' => $item->currency,
                ]);
            }
        }

        // A benefit that belongs to one line is priced against THAT line.
        $lineIndex = [];

        foreach ($items as $index => $item) {
            $lineIndex[(int) $item->getKey()] = $index;
        }

        $totals = $this->pricing->calculate(
            array_map(static fn (SaleItem $item): LineInput => new LineInput(
                unitPriceMinor: $item->unit_price_minor,
                addonsUnitTotalMinor: $item->addons_unit_total_minor,
                quantity: $item->quantity,
            ), $items),
            array_map(static fn (SaleAdjustment $adjustment): AdjustmentInput => new AdjustmentInput(
                type: $adjustment->type,
                basisPoints: $adjustment->basis_points,
                amountMinor: $adjustment->amount_minor,
                targetLine: $adjustment->sale_item_id === null
                    ? null
                    : ($lineIndex[$adjustment->sale_item_id] ?? throw SaleFailed::policy('A discount belongs to a line that is no longer on this sale.')),
            ), $adjustments),
        );

        foreach ($items as $index => $item) {
            $priced = $totals->lines[$index];

            // `save()` is a no-op for an unchanged row, so an edit to one line
            // does not rewrite every other line in the cart.
            $item->forceFill([
                'line_subtotal_minor' => $priced->subtotalMinor,
                'discount_allocated_minor' => $priced->discountAllocatedMinor,
                'line_total_minor' => $priced->totalMinor,
            ])->save();
        }

        foreach ($adjustments as $index => $adjustment) {
            $adjustment->forceFill(['amount_minor' => $totals->adjustmentAmounts[$index]])->save();
        }

        $locked->forceFill([
            'subtotal_minor' => $totals->subtotalMinor,
            'discount_total_minor' => $totals->discountTotalMinor,
            'surcharge_total_minor' => $totals->surchargeTotalMinor,
            'tax_total_minor' => $totals->taxTotalMinor,
            'grand_total_minor' => $totals->grandTotalMinor,
        ])->save();

        return $totals;
    }
}
