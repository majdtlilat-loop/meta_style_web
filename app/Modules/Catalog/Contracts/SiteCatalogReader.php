<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * The catalog as a center's public site may show it — by REFERENCE.
 *
 * The site stores service and category uuids, never a copy of a name or a
 * price: what a customer reads is always the live catalog, and an archived or
 * hidden service simply stops appearing. Every method returns allow-listed
 * arrays (no models, no internal ids, no costs or notes), in bounded queries.
 */
interface SiteCatalogReader
{
    /**
     * Every service and category uuid this center has, and whether it is
     * currently publicly visible. A uuid not listed is not this center's.
     *
     * @return array{services: array<string, bool>, categories: array<string, bool>}
     */
    public function references(): array;

    /**
     * Choices for the site editor: publicly visible services and categories,
     * in display order.
     *
     * @return array{services: list<array{uuid: string, name: string, category: string|null}>, categories: list<array{uuid: string, name: string}>}
     */
    public function options(string $locale): array;

    /**
     * Publicly visible services in the catalog's display order, optionally
     * narrowed to some services or to some categories.
     *
     * @param  list<string>|null  $serviceUuids
     * @param  list<string>|null  $categoryUuids
     * @return list<array{uuid: string, name: string, description: string|null, category: string|null, duration_minutes: int, price: string, price_from: bool, bookable: bool, image: array{url: string, alt: string|null}|null}>
     */
    public function services(?array $serviceUuids, ?array $categoryUuids, int $limit, string $locale): array;

    /**
     * Publicly visible categories in display order.
     *
     * @param  list<string>|null  $categoryUuids
     * @return list<array{uuid: string, name: string, description: string|null, image: array{url: string, alt: string|null}|null}>
     */
    public function categories(?array $categoryUuids, int $limit, string $locale): array;
}
