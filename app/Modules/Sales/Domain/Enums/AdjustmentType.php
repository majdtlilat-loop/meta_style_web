<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

/**
 * Manual, sale-level adjustments. Not promotions, not loyalty, not memberships —
 * those are engines with their own rules, and they arrive in their own phases
 * (docs/18-SALES.md §12).
 */
enum AdjustmentType: string
{
    case DiscountFixed = 'discount_fixed';

    /** Basis points of the SUBTOTAL. Never compounds with another percentage. */
    case DiscountPercent = 'discount_percent';

    case SurchargeFixed = 'surcharge_fixed';

    public function isDiscount(): bool
    {
        return $this !== self::SurchargeFixed;
    }

    public function isPercentage(): bool
    {
        return $this === self::DiscountPercent;
    }
}
