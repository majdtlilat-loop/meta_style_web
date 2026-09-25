<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Data\LineSnapshot;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Models\SaleItemAddon;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Illuminate\Support\Facades\DB;

/**
 * Writes a line snapshot onto a LOCKED draft.
 *
 * Only ever called from inside `SaleMutation::apply()`, so the position it
 * computes is read under the sale's row lock and two lines added at once cannot
 * share one. The totals are the mutation's job; this only records what was
 * picked.
 */
final class SaleLines
{
    public const MAX_LINES = 100;

    /**
     * @throws SaleFailed
     */
    public function append(Sale $locked, LineSnapshot $snapshot, int $quantity, ?string $note = null): SaleItem
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new \RuntimeException('SaleLines::append() must run inside the sale mutation.');
        }

        if ($quantity < 1 || $quantity > SalePricing::MAX_QUANTITY) {
            throw SaleFailed::policy('A line quantity must be between 1 and '.SalePricing::MAX_QUANTITY.'.');
        }

        $count = SaleItem::query()->where('sale_id', $locked->getKey())->count();

        if ($count >= self::MAX_LINES) {
            throw SaleFailed::policy('A sale may carry at most '.self::MAX_LINES.' lines.');
        }

        $position = (int) SaleItem::query()->where('sale_id', $locked->getKey())->max('position') + ($count > 0 ? 1 : 0);

        if ($snapshot->journeyStageId !== null) {
            $already = SaleItem::query()
                ->where('sale_id', $locked->getKey())
                ->where('journey_stage_id', $snapshot->journeyStageId)
                ->exists();

            // The unique index would refuse it anyway; saying so plainly is
            // kinder than a constraint violation.
            if ($already) {
                throw SaleFailed::policy('That service is already charged on this sale.');
            }
        }

        /** @var SaleItem $item */
        $item = SaleItem::query()->create([
            'sale_id' => $locked->getKey(),
            'position' => $position,
            'kind' => $snapshot->kind,
            'service_id' => $snapshot->serviceId,
            'service_variation_id' => $snapshot->serviceVariationId,
            'product_id' => $snapshot->productId,
            'journey_stage_id' => $snapshot->journeyStageId,
            'employee_id' => $snapshot->employeeId,
            'name' => $snapshot->name,
            'variation_name' => $snapshot->variationName,
            'quantity' => $quantity,
            'original_unit_price_minor' => $snapshot->unitPriceMinor,
            'price_source' => $snapshot->priceSource,
            'unit_price_minor' => $snapshot->unitPriceMinor,
            'addons_unit_total_minor' => $snapshot->addonsUnitTotalMinor(),
            'currency' => $snapshot->currency,
            'note' => $this->note($note),
            'offering_type' => $snapshot->offeringType,
            'offering_reference' => $snapshot->offeringReference,
        ]);

        foreach ($snapshot->addons as $index => $addon) {
            SaleItemAddon::query()->create([
                'sale_item_id' => $item->getKey(),
                'service_addon_id' => $addon['service_addon_id'],
                'name' => $addon['name'],
                'unit_price_minor' => $addon['unit_price_minor'],
                'currency' => $snapshot->currency,
                'sort_order' => $index,
            ]);
        }

        return $item;
    }

    public function note(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        if ($note === null || $note === '') {
            return null;
        }

        return mb_substr($note, 0, 190);
    }
}
