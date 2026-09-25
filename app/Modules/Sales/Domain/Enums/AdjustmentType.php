<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

/**
 * Sale adjustments.
 *
 * The three manual kinds are a person's decision, made with `sale.adjust` and a
 * reason (docs/18-SALES.md §12). Not promotions — there is no promotions
 * engine.
 *
 * A `benefit_discount` is different: it is what a customer is ENTITLED to —
 * points they redeem, a member's price, a service their package covers. Sales
 * does not decide it and does not know what kind of benefit it is. It is
 * created only through `SaleBenefits`, by the module that owns the benefit, and
 * carries that module's opaque source reference so the module can take it back
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 */
enum AdjustmentType: string
{
    case DiscountFixed = 'discount_fixed';

    /** Basis points of the payable BASE. Never compounds with another percentage. */
    case DiscountPercent = 'discount_percent';

    case SurchargeFixed = 'surcharge_fixed';

    /** A fixed discount a benefit module applied; never added or removed by hand. */
    case BenefitDiscount = 'benefit_discount';

    public function isDiscount(): bool
    {
        return $this !== self::SurchargeFixed;
    }

    public function isPercentage(): bool
    {
        return $this === self::DiscountPercent;
    }

    public function isBenefit(): bool
    {
        return $this === self::BenefitDiscount;
    }
}
