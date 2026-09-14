<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Pricing;

final readonly class PricedLine
{
    public function __construct(
        /** (unit + add-ons) × quantity */
        public int $subtotalMinor,
        /** This line's share of the sale-level discounts. */
        public int $discountAllocatedMinor,
        public int $totalMinor,
    ) {}
}
