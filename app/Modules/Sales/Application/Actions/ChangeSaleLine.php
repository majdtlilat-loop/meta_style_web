<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\SaleLines;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use App\Modules\Sales\Domain\SaleMutation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Everything that changes an existing line of a DRAFT: quantity, note, removal,
 * and the explicit price override.
 *
 * Each runs through `SaleMutation`, so it locks the sale, refuses a sale that is
 * no longer a draft by the time the lock is held, and re-prices the whole cart
 * in the same transaction (docs/18-SALES.md §§9, 19).
 */
final class ChangeSaleLine
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly SaleLines $lines,
        private readonly SaleMutation $mutation,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @param  array{quantity?: int|null, note?: string|null}  $input
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function update(Sale $sale, string $itemUuid, User $actingUser, array $input): SaleItem
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not change sales.');

        $before = null;

        [, $item] = $this->mutation->apply($sale, function (Sale $locked) use ($itemUuid, $input, &$before): SaleItem {
            $item = $this->item($locked, $itemUuid);
            $before = ['quantity' => $item->quantity];

            if (array_key_exists('quantity', $input) && $input['quantity'] !== null) {
                $quantity = (int) $input['quantity'];

                if ($quantity < 1 || $quantity > SalePricing::MAX_QUANTITY) {
                    throw SaleFailed::policy('A line quantity must be between 1 and '.SalePricing::MAX_QUANTITY.'.');
                }

                if ($item->journey_stage_id !== null && $quantity !== 1) {
                    throw SaleFailed::policy('A performed service is charged once.');
                }

                $item->quantity = $quantity;
            }

            if (array_key_exists('note', $input)) {
                $item->note = $this->lines->note($input['note']);
            }

            $item->save();

            return $item;
        });

        $this->audit->record('sale.line_updated', $actingUser, $sale,
            after: ['line' => $item->uuid, 'quantity' => $item->quantity],
            before: $before,
        );

        return $item->refresh();
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function remove(Sale $sale, string $itemUuid, User $actingUser): void
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not change sales.');

        [, $removed] = $this->mutation->apply($sale, function (Sale $locked) use ($itemUuid): array {
            $item = $this->item($locked, $itemUuid);

            if ($item->journey_stage_id !== null) {
                // A performed service stays on the bill. Removing it would be a
                // 100% discount without `sale.adjust`, and finalization would
                // put it straight back (VisitLines). Waiving it is an explicit,
                // reasoned price override or discount.
                throw SaleFailed::policy(
                    'A service performed on this visit stays on the bill. To charge less for it, change its price with a reason or apply a discount.',
                );
            }

            $summary = [
                'line' => $item->uuid,
                'kind' => $item->kind->value,
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
            ];

            // A draft line is not financial history yet; its add-ons cascade.
            $item->delete();

            return $summary;
        });

        $this->audit->record('sale.line_removed', $actingUser, $sale, before: $removed);
    }

    /**
     * Sets what a line is actually charged — or, with a null price, puts the
     * original back.
     *
     * `original_unit_price_minor` is never touched, so the catalog or visit
     * price stays recoverable, and the override carries who did it and why
     * (docs/18-SALES.md §13).
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function overridePrice(Sale $sale, string $itemUuid, User $actingUser, ?int $unitPriceMinor, ?string $reason): SaleItem
    {
        $this->access->ensure($actingUser, Permission::SaleAdjust, $sale->branch_id, 'You may not change prices.');

        $reason = $reason === null ? null : trim($reason);

        if ($unitPriceMinor !== null) {
            if ($unitPriceMinor < 0 || $unitPriceMinor > SalePricing::MAX_SUBTOTAL_MINOR) {
                throw SaleFailed::policy('That price is outside the allowed range.');
            }

            if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
                throw SaleFailed::policy('A price change needs a reason.');
            }
        }

        $before = null;

        [, $item] = $this->mutation->apply($sale, function (Sale $locked) use ($itemUuid, $unitPriceMinor, $reason, $actingUser, &$before): SaleItem {
            $item = $this->item($locked, $itemUuid);
            $before = ['unit_price_minor' => $item->unit_price_minor];

            if ($unitPriceMinor === null) {
                $item->forceFill([
                    'unit_price_minor' => $item->original_unit_price_minor,
                    'price_override_reason' => null,
                    'price_overridden_by_id' => null,
                    'price_overridden_by_label' => null,
                ])->save();

                return $item;
            }

            $item->forceFill([
                'unit_price_minor' => $unitPriceMinor,
                'price_override_reason' => $reason,
                'price_overridden_by_id' => $actingUser->uuid,
                'price_overridden_by_label' => $actingUser->name,
            ])->save();

            return $item;
        });

        $this->audit->record(
            $unitPriceMinor === null ? 'sale.price_override_cleared' : 'sale.price_overridden',
            $actingUser,
            $sale,
            after: [
                'line' => $item->uuid,
                'unit_price_minor' => $item->unit_price_minor,
                'original_unit_price_minor' => $item->original_unit_price_minor,
            ],
            before: $before,
            reason: $reason,
            severity: AuditSeverity::Notice,
        );

        return $item->refresh();
    }

    private function item(Sale $locked, string $itemUuid): SaleItem
    {
        /** @var SaleItem|null $item */
        $item = SaleItem::query()
            ->where('sale_id', $locked->getKey())
            ->where('uuid', $itemUuid)
            ->first();

        if (! $item instanceof SaleItem) {
            throw SaleFailed::policy('That line is not on this sale.');
        }

        return $item;
    }
}
