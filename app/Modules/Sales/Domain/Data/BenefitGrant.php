<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Data;

/**
 * What a benefit module grants a draft: a fixed discount, the label a cashier
 * sees, and the module's own reference for it.
 *
 * `sourceType` and `sourceReference` are opaque to Sales. They exist so the
 * owning module can find — and reverse — its own discount later.
 */
final readonly class BenefitGrant
{
    public function __construct(
        public string $sourceType,
        public string $sourceReference,
        public int $amountMinor,
        public string $label,
    ) {}
}
