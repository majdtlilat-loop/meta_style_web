<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Domain\Data\BenefitGrant;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use App\Modules\Sales\Domain\SaleMutation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The approved seam through which a customer's benefit reaches a DRAFT sale.
 *
 * Loyalty points, a member's price, a service a package covers: each is decided
 * by the module that owns it, and lands here as a FIXED discount — on the whole
 * sale, or on one line — in the same transaction as that module's own record:
 *
 *     BEGIN
 *       lock the sale                               (SaleMutation)
 *       refuse unless the LOCKED row is a draft     → no benefit after publication
 *       the line, if any, is this sale's and carries no other benefit
 *       $grant($locked, $line)                      ← the module locks ITS row,
 *                                                     validates, writes its ledger
 *       write the benefit discount
 *       re-price                                    ← SalePricing bounds it
 *     COMMIT
 *
 * Lock order: the sale, then the module's own row — the order every benefit
 * path uses. If re-pricing refuses (a discount larger than its line), the
 * module's write rolls back with it.
 *
 * Sales never interprets a benefit. It stores the module's opaque source so the
 * module can withdraw it; nothing else may remove one (`AdjustSale` refuses),
 * and a line carrying one cannot be changed until it is withdrawn
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 *
 * Authority is the till's own: `sale.create` at the sale's branch, with `pos`.
 * A customer's entitled benefit is not a discretionary discount, so it does not
 * need `sale.adjust`. The module adds its own entitlement and permission.
 */
final class SaleBenefits
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly SaleMutation $mutation,
    ) {}

    /**
     * @param  callable(Sale, SaleItem|null): BenefitGrant  $grant  runs under the sale lock
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function apply(Sale $sale, User $actingUser, ?string $lineUuid, callable $grant): SaleAdjustment
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not change sales.');

        [, $adjustment] = $this->mutation->apply($sale, function (Sale $locked) use ($lineUuid, $grant, $actingUser): SaleAdjustment {
            $line = null;

            if ($lineUuid !== null) {
                /** @var SaleItem|null $line */
                $line = SaleItem::query()->where('sale_id', $locked->getKey())->where('uuid', $lineUuid)->first();

                if (! $line instanceof SaleItem) {
                    throw SaleFailed::policy('That line is not on this sale.');
                }

                if ($this->lineHasBenefit($line)) {
                    throw SaleFailed::policy('That line already carries a benefit. Withdraw it first.');
                }
            }

            $granted = $grant($locked, $line);
            $this->assertGrant($granted);

            /** @var SaleAdjustment $adjustment */
            $adjustment = SaleAdjustment::query()->create([
                'sale_id' => $locked->getKey(),
                'sale_item_id' => $line?->getKey(),
                'type' => AdjustmentType::BenefitDiscount,
                'basis_points' => null,
                'amount_minor' => $granted->amountMinor,
                'reason' => $granted->label,
                'position' => (int) SaleAdjustment::query()->where('sale_id', $locked->getKey())->max('position') + 1,
                'source_type' => $granted->sourceType,
                'source_reference' => $granted->sourceReference,
                'created_by_id' => $actingUser->uuid,
                'created_by_label' => $actingUser->name,
            ]);

            return $adjustment;
        });

        return $adjustment->refresh();
    }

    /**
     * Takes a benefit back from a DRAFT. `$reclaim` runs under the sale lock,
     * before the discount is removed, so the module's reversal and the
     * re-priced sale commit together.
     *
     * @param  callable(Sale, SaleAdjustment): void  $reclaim
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function withdraw(Sale $sale, User $actingUser, string $sourceType, string $sourceReference, callable $reclaim): void
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not change sales.');

        $this->mutation->apply($sale, function (Sale $locked) use ($sourceType, $sourceReference, $reclaim): void {
            /** @var SaleAdjustment|null $adjustment */
            $adjustment = SaleAdjustment::query()
                ->where('sale_id', $locked->getKey())
                ->where('source_type', $sourceType)
                ->where('source_reference', $sourceReference)
                ->first();

            if (! $adjustment instanceof SaleAdjustment) {
                throw SaleFailed::policy('That benefit is not on this sale.');
            }

            $reclaim($locked, $adjustment);

            $adjustment->delete();
        });
    }

    /**
     * The benefit discounts a sale carries, oldest first — for a module to find
     * its own, or for display.
     *
     * @return list<SaleAdjustment>
     */
    public function on(Sale $sale, ?string $sourceType = null): array
    {
        $query = SaleAdjustment::query()
            ->where('sale_id', $sale->getKey())
            ->where('type', AdjustmentType::BenefitDiscount->value)
            ->orderBy('position')
            ->orderBy('id');

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        /** @var list<SaleAdjustment> $adjustments */
        $adjustments = $query->get()->all();

        return $adjustments;
    }

    public function lineHasBenefit(SaleItem $line): bool
    {
        return SaleAdjustment::query()->where('sale_item_id', $line->getKey())->exists();
    }

    /**
     * @throws SaleFailed
     */
    private function assertGrant(BenefitGrant $grant): void
    {
        if (preg_match('/^[a-z][a-z_]{0,31}$/', $grant->sourceType) !== 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $grant->sourceReference) !== 1) {
            throw new \LogicException('A benefit grant needs a lowercase source type and a uuid reference.');
        }

        if ($grant->amountMinor < 1 || $grant->amountMinor > SalePricing::MAX_SUBTOTAL_MINOR) {
            throw SaleFailed::policy('A benefit must be worth something, and no more than a sale may be.');
        }

        $label = trim($grant->label);

        if (mb_strlen($label) < 3 || mb_strlen($label) > 190) {
            throw new \LogicException('A benefit grant needs a label of 3 to 190 characters.');
        }
    }
}
