<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Packages\Contracts\SitePackageReader;
use App\Modules\Packages\Domain\Models\PackageDefinition;

/**
 * {@see SitePackageReader} over the tenant's package definitions.
 */
final class SitePackageQuery implements SitePackageReader
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function references(): array
    {
        $references = [];
        foreach (PackageDefinition::query()->get(['id', 'uuid', 'archived_at']) as $definition) {
            $references[$definition->uuid] = $definition->archived_at === null;
        }

        return $references;
    }

    public function offered(): bool
    {
        return $this->entitlements->enabled('packages');
    }

    public function options(string $locale): array
    {
        return PackageDefinition::query()->active()->orderBy('sort_order')->orderBy('id')->get(['id', 'uuid', 'name'])
            ->map(fn (PackageDefinition $definition): array => ['uuid' => $definition->uuid, 'name' => $definition->name->get($locale)])
            ->values()->all();
    }

    public function packages(?array $uuids, string $locale): array
    {
        if ($uuids === [] || ! $this->offered()) {
            return [];
        }

        $query = PackageDefinition::query()->active()->withSum('items', 'quantity')->orderBy('sort_order')->orderBy('id');
        if ($uuids !== null) {
            $query->whereIn('uuid', $uuids);
        }
        $currency = Currency::default();

        return $query->get()->map(fn (PackageDefinition $definition): array => [
            'uuid' => $definition->uuid,
            'name' => $definition->name->get($locale),
            'price' => Money::fromMinor($definition->price_minor, $currency)->formatted($locale),
            'validity_days' => $definition->validity_days,
            'sessions' => (int) $definition->getAttribute('items_sum_quantity'),
        ])->values()->all();
    }
}
