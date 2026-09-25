<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Money\Currency;
use App\Modules\Catalog\Contracts\SiteCatalogReader;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Catalog\Domain\Models\ServiceVariation;

/**
 * {@see SiteCatalogReader} over the tenant catalog.
 *
 * Visibility is the catalog's own `publiclyVisible()` scope (active, public,
 * not archived) — the same rule as the electronic menu, so the site can never
 * show a service the menu would hide. Relations are eager-loaded; a call is a
 * fixed number of queries however many services the center has.
 */
final class SiteCatalogQuery implements SiteCatalogReader
{
    public function references(): array
    {
        $services = [];
        foreach (Service::query()->get(['id', 'uuid', 'is_active', 'is_public', 'archived_at']) as $service) {
            $services[$service->uuid] = $service->is_active && $service->is_public && $service->archived_at === null;
        }
        $categories = [];
        foreach (ServiceCategory::query()->get(['id', 'uuid', 'is_active', 'is_public', 'archived_at']) as $category) {
            $categories[$category->uuid] = $category->is_active && $category->is_public && $category->archived_at === null;
        }

        return ['services' => $services, 'categories' => $categories];
    }

    public function options(string $locale): array
    {
        $categories = ServiceCategory::query()->publiclyVisible()->get();
        $names = [];
        foreach ($categories as $category) {
            $names[$category->id] = $category->name->get($locale);
        }

        return [
            'services' => Service::query()->publiclyVisible()->get()
                ->map(fn (Service $s): array => [
                    'uuid' => $s->uuid,
                    'name' => $s->name->get($locale),
                    'category' => $s->service_category_id === null ? null : ($names[$s->service_category_id] ?? null),
                ])->values()->all(),
            'categories' => $categories
                ->map(fn (ServiceCategory $c): array => ['uuid' => $c->uuid, 'name' => $c->name->get($locale)])
                ->values()->all(),
        ];
    }

    public function services(?array $serviceUuids, ?array $categoryUuids, int $limit, string $locale): array
    {
        if ($serviceUuids === [] || $categoryUuids === []) {
            return [];
        }

        $query = Service::query()->publiclyVisible()->with([
            'media',
            'category',
            'variations' => fn ($q) => $q->where('is_active', true),
        ]);

        if ($serviceUuids !== null) {
            $query->whereIn('uuid', $serviceUuids);
        }

        if ($categoryUuids !== null) {
            $query->whereIn('service_category_id', ServiceCategory::query()->publiclyVisible()->whereIn('uuid', $categoryUuids)->select('id'));
        }

        $currency = Currency::default();

        return $query->limit(max(1, min(48, $limit)))->get()
            ->map(function (Service $service) use ($currency, $locale): array {
                // From the cheapest active variation when there are several; a
                // variation with no price of its own inherits the service's.
                $cheapest = $service->variations
                    ->sortBy(fn (ServiceVariation $v): int => $v->price_minor ?? $service->price_minor)
                    ->first();
                $price = $cheapest instanceof ServiceVariation
                    ? $cheapest->effectivePrice($service, $currency)
                    : $service->price($currency);

                return [
                    'uuid' => $service->uuid,
                    'name' => $service->name->get($locale),
                    'description' => $service->short_description?->get($locale),
                    'category' => $service->category?->name->get($locale),
                    'duration_minutes' => $service->duration_minutes,
                    'price' => $price->formatted($locale),
                    'price_from' => $service->variations->count() > 1,
                    'bookable' => $service->is_online_bookable,
                    'image' => $this->image($service->media->first(), $locale),
                ];
            })->values()->all();
    }

    public function categories(?array $categoryUuids, int $limit, string $locale): array
    {
        if ($categoryUuids === []) {
            return [];
        }

        $query = ServiceCategory::query()->publiclyVisible()->with('media');
        if ($categoryUuids !== null) {
            $query->whereIn('uuid', $categoryUuids);
        }

        return $query->limit(max(1, min(48, $limit)))->get()
            ->map(fn (ServiceCategory $category): array => [
                'uuid' => $category->uuid,
                'name' => $category->name->get($locale),
                'description' => $category->description?->get($locale),
                'image' => $this->image($category->media->first(), $locale),
            ])->values()->all();
    }

    /**
     * @return array{url: string, alt: string|null}|null
     */
    private function image(?MediaItem $item, string $locale): ?array
    {
        if ($item === null) {
            return null;
        }
        $url = $item->url();

        return $url === null ? null : ['url' => $url, 'alt' => $item->alt_text?->get($locale)];
    }
}
