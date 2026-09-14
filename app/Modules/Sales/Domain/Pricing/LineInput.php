<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Pricing;

/**
 * One line, as the pricing service sees it: integers and nothing else.
 *
 * No catalog lookup happens past this point. Whatever produced these numbers —
 * the catalog when the line was added, a visit's own snapshot, or an explicit
 * override — has already happened, and finalization re-prices from exactly
 * these values (docs/18-SALES.md §11).
 */
final readonly class LineInput
{
    public function __construct(
        public int $unitPriceMinor,
        public int $addonsUnitTotalMinor,
        public int $quantity,
    ) {}
}
