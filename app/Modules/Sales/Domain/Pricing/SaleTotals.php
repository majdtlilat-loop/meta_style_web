<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Pricing;

/**
 * The only totals a sale ever has. Produced by {@see SalePricing}, written to
 * the sale by the single mutation writer, copied onto the invoice at
 * finalization — and never computed anywhere else.
 */
final readonly class SaleTotals
{
    /**
     * @param  list<PricedLine>  $lines  in the order the lines were given
     * @param  list<int>  $adjustmentAmounts  resolved amount per adjustment, in order
     */
    public function __construct(
        public int $subtotalMinor,
        public int $discountTotalMinor,
        public int $surchargeTotalMinor,
        public int $taxTotalMinor,
        public int $grandTotalMinor,
        public array $lines,
        public array $adjustmentAmounts,
    ) {}
}
