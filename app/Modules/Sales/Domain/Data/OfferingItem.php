<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Data;

use App\Kernel\Localization\TranslatedText;

/**
 * One sellable item from an `OfferingCatalog`, priced at the moment it is
 * offered. Becomes a line snapshot; nothing re-reads the catalog afterwards.
 */
final readonly class OfferingItem
{
    public function __construct(
        public string $reference,
        public TranslatedText $name,
        public int $unitPriceMinor,
    ) {}
}
