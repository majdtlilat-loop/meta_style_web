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
    ) {}
}
