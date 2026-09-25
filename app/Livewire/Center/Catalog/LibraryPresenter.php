<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Catalog\Domain\Models\ServiceVariation;

/**
 * Shapes catalog rows into the plain arrays the library view renders.
 *
 * Names resolve in the VIEWER's language first, then the center's primary
 * language, then whatever exists — never forced to English, never blank while
 * any translation exists (docs/07-LOCALIZATION.md §5.1).
 */
final class LibraryPresenter
{
    public function name(?TranslatedText $text): string
    {
        return $text?->get(app()->getLocale()) ?? '';
    }

    /**
     * @return array{uuid: string, name: string, description: string, count: int, active: bool, public: bool, image: string|null, initial: string}
     */
    public function category(ServiceCategory $category): array
    {
        $name = $this->name($category->name);

        return [
            'uuid' => $category->uuid,
            'name' => $name,
            'description' => $this->name($category->description),
            'count' => (int) ($category->getAttribute('live_services_count') ?? 0),
            'active' => $category->is_active,
            'public' => $category->is_public,
            'image' => $category->primaryMedia()?->url(),
            'initial' => $this->initial($name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function service(Service $service, bool $showCategory): array
    {
        $name = $this->name($service->name);
        $variations = $service->variations->filter(fn (ServiceVariation $v): bool => $v->is_active);

        $prices = $variations->map(fn (ServiceVariation $v): int => $v->price_minor ?? $service->price_minor)
            ->push($service->price_minor);
        $minimum = (int) $prices->min();

        $meta = array_values(array_filter([
            $showCategory ? $this->name($service->category?->name) : null,
            $this->name($service->department?->name),
            $variations->isNotEmpty()
                ? trans_choice('manager_catalog.services.variations', $variations->count(), ['count' => $variations->count()])
                : null,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return [
            'uuid' => $service->uuid,
            'name' => $name,
            'initial' => $this->initial($name),
            'meta' => implode(' · ', $meta),
            'duration' => $this->duration($service->duration_minutes),
            'price_minor' => $minimum,
            'price_from' => $variations->isNotEmpty() && $minimum !== (int) $prices->max(),
            'image' => $service->primaryMedia()?->url(),
            'active' => $service->is_active,
            'public' => $service->is_public,
            'online' => $service->is_online_bookable,
            'archived' => $service->isArchived(),
            'category' => $service->category?->uuid,
        ];
    }

    /**
     * The lists the library renders. The whole library, unfiltered, is one
     * section per category — so a service can be dragged from one to another
     * — with the uncategorised ones last; one category, or any filtered view,
     * is a single list. A filtered list is not the whole list, so it is never
     * sortable: a position in it means nothing in the real order.
     *
     * @param  list<Service>  $services
     * @param  list<array{uuid: string, name: string, description: string, count: int, active: bool, public: bool, image: string|null, initial: string}>  $categories
     * @return list<array{key: string|null, title: string|null, category: array<string, mixed>|null, sortable: bool, rows: list<array<string, mixed>>}>
     */
    public function sections(array $services, array $categories, string $current, bool $filtered, bool $mayReorder): array
    {
        if ($filtered || $current !== '') {
            return [[
                'key' => $filtered ? null : $current,
                'title' => null,
                'category' => null,
                'sortable' => ! $filtered && $mayReorder,
                'rows' => array_map(fn (Service $s): array => $this->service($s, $current === '' || $current === 'none'), $services),
            ]];
        }

        $byCategory = [];
        foreach ($services as $service) {
            $category = $service->category;
            $key = $category instanceof ServiceCategory && ! $category->isArchived() ? $category->uuid : 'none';
            $byCategory[$key][] = $this->service($service, false);
        }

        $sections = [];
        foreach ($categories as $category) {
            $sections[] = [
                'key' => $category['uuid'],
                'title' => $category['name'],
                'category' => $category,
                'sortable' => $mayReorder,
                'rows' => $byCategory[$category['uuid']] ?? [],
            ];
        }

        if (($byCategory['none'] ?? []) !== [] || $categories === []) {
            $sections[] = [
                'key' => 'none',
                'title' => __('manager_catalog.categories.uncategorised'),
                'category' => null,
                'sortable' => $mayReorder,
                'rows' => $byCategory['none'] ?? [],
            ];
        }

        return $sections;
    }

    /**
     * @param  array{live: int, active: int, inactive: int, archived: int, uncategorised: int}  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    public function statusTabs(array $counts): array
    {
        return [
            ['value' => '', 'label' => __('manager_catalog.filters.status_all'), 'count' => $counts['live']],
            ['value' => 'active', 'label' => __('ui.states.active'), 'count' => $counts['active']],
            ['value' => 'inactive', 'label' => __('ui.states.inactive'), 'count' => $counts['inactive']],
            ['value' => 'archived', 'label' => __('ui.states.archived'), 'count' => $counts['archived']],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function visibilityOptions(): array
    {
        return [
            ['value' => '', 'label' => __('manager_catalog.filters.visibility_any')],
            ['value' => 'public', 'label' => __('manager_catalog.filters.visibility_public')],
            ['value' => 'hidden', 'label' => __('manager_catalog.filters.visibility_hidden')],
            ['value' => 'online', 'label' => __('manager_catalog.filters.visibility_online')],
            ['value' => 'offline', 'label' => __('manager_catalog.filters.visibility_offline')],
        ];
    }

    public function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => __('manager_catalog.duration.minutes', ['count' => $rest]),
            $rest === 0 => __('manager_catalog.duration.hours', ['count' => $hours]),
            default => __('manager_catalog.duration.hours_minutes', ['hours' => $hours, 'minutes' => $rest]),
        };
    }

    private function initial(string $name): string
    {
        return mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '·';
    }
}
