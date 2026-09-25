<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Sales\Contracts\OfferingCatalog;
use App\Modules\Sales\Domain\Data\LineSnapshot;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;

/**
 * The offerings other modules sell through the till, found by container tag.
 *
 * Sales asks; the catalog prices. The line records the type and reference so the
 * owning module can recognise its own lines later — Sales never interprets them
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§11, 15).
 */
final class Offerings
{
    public const TAG = 'sales.offering_catalogs';

    public function __construct(
        private readonly Container $container,
        private readonly SalesAccess $access,
    ) {}

    /**
     * What this person may put on a sale now: `pos` and `sale.create`, then
     * whatever each catalog offers (each checks its own entitlement).
     *
     * @return list<array{type: string, reference: string, name: TranslatedText, unit_price_minor: int}>
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function availableFor(User $user): array
    {
        $this->access->ensure($user, Permission::SaleCreate, 0, 'You may not sell.');

        return $this->available();
    }

    /**
     * Everything sellable now, grouped by catalog type.
     *
     * @return list<array{type: string, reference: string, name: TranslatedText, unit_price_minor: int}>
     */
    public function available(): array
    {
        $items = [];

        foreach ($this->catalogs() as $catalog) {
            foreach ($catalog->available() as $item) {
                $items[] = [
                    'type' => $catalog->type(),
                    'reference' => $item->reference,
                    'name' => $item->name,
                    'unit_price_minor' => $item->unitPriceMinor,
                ];
            }
        }

        return $items;
    }

    /**
     * @throws SaleFailed
     */
    public function snapshot(Sale $sale, string $type, string $reference): LineSnapshot
    {
        foreach ($this->catalogs() as $catalog) {
            if ($catalog->type() !== $type) {
                continue;
            }

            $item = $catalog->offer($reference, $sale);

            if ($item->unitPriceMinor < 0 || $item->unitPriceMinor > SalePricing::MAX_SUBTOTAL_MINOR) {
                throw SaleFailed::policy('That item has a price outside the allowed range.');
            }

            return new LineSnapshot(
                kind: SaleItemKind::Offering,
                name: $item->name,
                unitPriceMinor: $item->unitPriceMinor,
                priceSource: PriceSource::Catalog,
                currency: $sale->currency,
                offeringType: $type,
                offeringReference: $item->reference,
            );
        }

        throw SaleFailed::policy('That is not something this till can sell.');
    }

    /**
     * @return list<OfferingCatalog>
     */
    private function catalogs(): array
    {
        $catalogs = [];

        foreach ($this->container->tagged(self::TAG) as $catalog) {
            if ($catalog instanceof OfferingCatalog) {
                $catalogs[] = $catalog;
            }
        }

        return $catalogs;
    }
}
