<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Sales\Domain\Data\LineSnapshot;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * Turns a catalog pick into a line snapshot, once, at the moment it is added.
 *
 * ## The commercial seam, not the menu
 *
 * The public menu shows prices; it does not charge them, and its presenter is
 * free to change how it displays a variation without anybody's till noticing.
 * This class resolves what a line COSTS — base or variation price, plus every
 * add-on — and validates that it may be sold here: active, not archived, offered
 * at this branch, the variation belonging to the service, each add-on attached
 * to it (docs/18-SALES.md §11).
 *
 * After this, nothing reads the catalog again for this line. A price change
 * tomorrow does not reach it, and finalization re-prices from the snapshot.
 */
final class LinePriceResolver
{
    private const MAX_ADDONS = 20;

    /**
     * @param  list<string>  $addonUuids
     *
     * @throws SaleFailed
     */
    public function service(Sale $sale, string $serviceUuid, ?string $variationUuid = null, array $addonUuids = []): LineSnapshot
    {
        /** @var Service|null $service */
        $service = Service::query()->where('uuid', $serviceUuid)->first();

        if (! $service instanceof Service || ! $service->is_active || $service->isArchived()) {
            throw SaleFailed::policy('That service is not available to sell.');
        }

        if (! $service->isAvailableAtBranch($sale->branch_id)) {
            throw SaleFailed::policy('That service is not offered at this branch.');
        }

        $variation = null;
        $unitPrice = $service->price_minor;

        if ($variationUuid !== null && $variationUuid !== '') {
            /** @var ServiceVariation|null $variation */
            $variation = ServiceVariation::query()
                ->where('uuid', $variationUuid)
                ->where('service_id', $service->id)
                ->first();

            if (! $variation instanceof ServiceVariation || ! $variation->is_active) {
                throw SaleFailed::policy('That option is not available for this service.');
            }

            $unitPrice = $variation->effectivePrice($service, $sale->currencyCode())->minor;
        }

        $addonUuids = array_values(array_unique(array_filter($addonUuids, 'is_string')));

        if (count($addonUuids) > self::MAX_ADDONS) {
            throw SaleFailed::policy('That is more add-ons than one line may carry.');
        }

        $addons = [];

        if ($addonUuids !== []) {
            /** @var list<ServiceAddon> $found */
            $found = $service->addons()
                ->whereIn('service_addons.uuid', $addonUuids)
                ->where('service_addons.is_active', true)
                ->get()
                ->all();

            if (count($found) !== count($addonUuids)) {
                throw SaleFailed::policy('One of those add-ons is not available for this service.');
            }

            foreach ($found as $addon) {
                $addons[] = [
                    'service_addon_id' => $addon->id,
                    'name' => $addon->name,
                    'unit_price_minor' => $addon->price_minor,
                ];
            }
        }

        return new LineSnapshot(
            kind: SaleItemKind::Service,
            name: $service->name,
            unitPriceMinor: $unitPrice,
            priceSource: PriceSource::Catalog,
            currency: $sale->currency,
            variationName: $variation?->name,
            addons: $addons,
            serviceId: $service->id,
            serviceVariationId: $variation?->id,
        );
    }

    /**
     * @throws SaleFailed
     */
    public function product(Sale $sale, string $productUuid): LineSnapshot
    {
        /** @var Product|null $product */
        $product = Product::query()->where('uuid', $productUuid)->first();

        if (! $product instanceof Product || ! $product->isSellable()) {
            throw SaleFailed::policy('That product is not available to sell.');
        }

        return new LineSnapshot(
            kind: SaleItemKind::Product,
            name: $product->name,
            unitPriceMinor: $product->price_minor,
            priceSource: PriceSource::Catalog,
            currency: $sale->currency,
            productId: $product->id,
        );
    }

    /**
     * A line typed at the till. The caller has already checked `sale.adjust`
     * and a reason: this is a price no catalog agreed to.
     *
     * @throws SaleFailed
     */
    public function custom(Sale $sale, string $name, int $unitPriceMinor): LineSnapshot
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw SaleFailed::policy('A custom line needs a name of at most 120 characters.');
        }

        if ($unitPriceMinor < 0) {
            throw SaleFailed::policy('A price cannot be negative. Use a discount instead.');
        }

        return new LineSnapshot(
            kind: SaleItemKind::Custom,
            // Stored in every launch language so it renders on an Arabic or
            // Kurdish invoice exactly as typed rather than falling back.
            name: TranslatedText::fromArray(['en' => $name, 'ar' => $name, 'ckb' => $name]),
            unitPriceMinor: $unitPriceMinor,
            priceSource: PriceSource::Manual,
            currency: $sale->currency,
        );
    }
}
