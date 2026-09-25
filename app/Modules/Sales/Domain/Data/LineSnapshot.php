<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Data;

use App\Kernel\Localization\TranslatedText;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Enums\SaleItemKind;

/**
 * Everything a sale line needs, resolved ONCE at the moment it is added.
 *
 * Built from the catalog, from a visit's own price snapshot, or from what a
 * cashier typed for a custom line. After this point nothing re-reads the source:
 * the line is the record of what was offered at that moment
 * (docs/18-SALES.md §§5, 11).
 */
final readonly class LineSnapshot
{
    /**
     * @param  list<array{service_addon_id: int|null, name: TranslatedText, unit_price_minor: int}>  $addons
     */
    public function __construct(
        public SaleItemKind $kind,
        public TranslatedText $name,
        public int $unitPriceMinor,
        public PriceSource $priceSource,
        public string $currency,
        public ?TranslatedText $variationName = null,
        public array $addons = [],
        public ?int $serviceId = null,
        public ?int $serviceVariationId = null,
        public ?int $productId = null,
        public ?int $journeyStageId = null,
        public ?int $employeeId = null,
        /** For an offering: the catalog type and the item's uuid in it. */
        public ?string $offeringType = null,
        public ?string $offeringReference = null,
    ) {}

    /**
     * The same line, recording who performed it.
     */
    public function withEmployee(?int $employeeId): self
    {
        return new self(
            kind: $this->kind,
            name: $this->name,
            unitPriceMinor: $this->unitPriceMinor,
            priceSource: $this->priceSource,
            currency: $this->currency,
            variationName: $this->variationName,
            addons: $this->addons,
            serviceId: $this->serviceId,
            serviceVariationId: $this->serviceVariationId,
            productId: $this->productId,
            journeyStageId: $this->journeyStageId,
            employeeId: $employeeId,
            offeringType: $this->offeringType,
            offeringReference: $this->offeringReference,
        );
    }

    public function addonsUnitTotalMinor(): int
    {
        return array_sum(array_map(static fn (array $addon): int => $addon['unit_price_minor'], $this->addons));
    }
}
