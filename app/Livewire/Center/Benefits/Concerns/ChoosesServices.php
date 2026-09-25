<?php

declare(strict_types=1);

namespace App\Livewire\Center\Benefits\Concerns;

use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;

/**
 * The services a membership benefit or a package item may name: the ACTIVE
 * catalog, in menu order, with each service's active variations. Options for a
 * select only — the Actions check every uuid again against the live catalog.
 */
trait ChoosesServices
{
    /**
     * @return list<array{uuid: string, name: string, variations: list<array{uuid: string, name: string}>}>
     */
    protected function serviceChoices(bool $withVariations = false): array
    {
        $query = Service::query()->active()->limit(300);

        if ($withVariations) {
            $query->with(['variations' => fn ($variations) => $variations->where('is_active', true)]);
        }

        $locale = app()->getLocale();

        return array_values($query->get()->map(fn (Service $service): array => [
            'uuid' => $service->uuid,
            'name' => (string) $service->name->get($locale),
            'variations' => $withVariations
                ? array_values($service->variations->map(fn (ServiceVariation $variation): array => [
                    'uuid' => $variation->uuid,
                    'name' => (string) $variation->name->get($locale),
                ])->all())
                : [],
        ])->all());
    }
}
