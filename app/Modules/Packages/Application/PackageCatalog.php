<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Sales\Contracts\OfferingCatalog;
use App\Modules\Sales\Domain\Data\OfferingItem;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * Service packages, sold through the till as an ordinary line
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §15).
 *
 * Selling a new package needs the `packages` entitlement. Sales prices the line
 * from `offer()` — never from the browser — and knows nothing else about it.
 */
final class PackageCatalog implements OfferingCatalog
{
    public const TYPE = 'package';

    public function __construct(private readonly Entitlements $entitlements) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function available(): array
    {
        if (! $this->entitlements->enabled('packages')) {
            return [];
        }

        $items = [];

        foreach (PackageDefinition::query()->active()->orderBy('sort_order')->orderBy('id')->limit(100)->get() as $definition) {
            $items[] = new OfferingItem($definition->uuid, $definition->name, $definition->price_minor);
        }

        return $items;
    }

    public function offer(string $reference, Sale $sale): OfferingItem
    {
        $this->entitlements->ensure('packages');

        /** @var PackageDefinition|null $definition */
        $definition = PackageDefinition::query()->active()->where('uuid', $reference)->withCount('items')->first();

        if (! $definition instanceof PackageDefinition || (int) $definition->getAttribute('items_count') < 1) {
            throw SaleFailed::policy('That package is not available to sell.');
        }

        return new OfferingItem($definition->uuid, $definition->name, $definition->price_minor);
    }
}
