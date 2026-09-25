<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Pricing;

use App\Modules\Sales\Domain\Enums\AdjustmentType;

final readonly class AdjustmentInput
{
    public function __construct(
        public AdjustmentType $type,
        /** Only for percentages: 1250 is 12.5%. */
        public ?int $basisPoints,
        /** Only for fixed adjustments: the amount entered, in minor units. */
        public int $amountMinor,
        /**
         * The index of the one line a discount belongs to, or null for a
         * sale-level adjustment. Only a fixed discount may target a line.
         */
        public ?int $targetLine = null,
    ) {}
}
