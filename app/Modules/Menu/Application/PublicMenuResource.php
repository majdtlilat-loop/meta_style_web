<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Menu\Domain\MenuPresentation;
use Illuminate\Support\Collection;

/**
 * Turns the public menu read model into JSON.
 *
 * EVERY FIELD IS LISTED EXPLICITLY. Not `$model->toArray()` minus a deny-list —
 * an allow-list. The difference is the whole security property: a deny-list is
 * defeated the day somebody adds a `cost_price` or `internal_note` column and
 * forgets this file, and that day will come. Here, a new column simply does not
 * appear until someone deliberately adds it.
 *
 * Nothing here emits an internal id. Uuids only (docs/08-AUDIT-SECURITY.md §19).
 */
final class PublicMenuResource
{
    public function __construct(
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
    ) {}

    /**
     * @param  array{presentation: MenuPresentation, branch: Branch|null, branches: Collection<int, Branch>, categories: Collection<int, ServiceCategory>, departments: Collection<int, Department>, services: Collection<int, Service>, employees: Collection<int, Employee>, currency: Currency}  $menu
     * @return array<string, mixed>
     */
    public function toArray(array $menu, string $centerName, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $currency = $menu['currency'];

        return [
            'center' => [
                'name' => $centerName,
                'locale' => $locale,
                'direction' => $this->languages->direction($locale),
                'locales' => array_map(fn (string $code): array => [
                    'code' => $code,
                    'name' => $this->languages->nativeName($code),
                    'direction' => $this->languages->direction($code),
                ], $this->locales->enabled()),
                'currency' => $currency->value,
            ],

            'presentation' => [
                'template' => $menu['presentation']->templateKey,
                'theme' => $menu['presentation']->theme,
                'sections' => $menu['presentation']->visibleSections(),
            ],

            'branch' => $menu['branch'] instanceof Branch
                ? $this->branch($menu['branch'], $locale)
                : null,

            'branches' => $menu['branches']
                ->map(fn (Branch $branch): array => $this->branch($branch, $locale))
                ->values()->all(),

            'departments' => $menu['departments']
                ->map(fn (Department $d): array => [
                    'uuid' => $d->uuid,
                    'name' => $d->name->get($locale),
                    'description' => $d->description?->get($locale),
                    'image' => $this->image($d->primaryMedia(), $locale),
                ])->values()->all(),

            'categories' => $menu['categories']
                ->map(fn (ServiceCategory $c): array => [
                    'uuid' => $c->uuid,
                    'name' => $c->name->get($locale),
                    'description' => $c->description?->get($locale),
                    'image' => $this->image($c->primaryMedia(), $locale),
                ])->values()->all(),

            'services' => $menu['services']
                ->map(fn (Service $s): array => $this->service($s, $currency, $locale))
                ->values()->all(),

            // Name only. No email, no phone, no branch, no login, no status.
            'employees' => $menu['employees']
                ->map(fn (Employee $e): array => [
                    'uuid' => $e->uuid,
                    'name' => $e->name->get($locale),
                ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branch(Branch $branch, string $locale): array
    {
        return [
            'uuid' => $branch->uuid,
            'name' => $branch->name->get($locale),
            'address' => $branch->address?->get($locale),
            'phone' => $branch->phone,
            'whatsapp' => $branch->whatsapp,
            'email' => $branch->email,
            'map_url' => $branch->map_url,
            'latitude' => $branch->latitude,
            'longitude' => $branch->longitude,
            'timezone' => $branch->timezone,
            'image' => $this->image($branch->primaryMedia(), $locale),
            'hours' => $branch->relationLoaded('workingHours')
                ? $branch->workingHours->map(fn ($h): array => [
                    'day_of_week' => $h->day_of_week,
                    'opens_at' => mb_substr($h->opens_at, 0, 5),
                    'closes_at' => mb_substr($h->closes_at, 0, 5),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function service(Service $service, Currency $currency, string $locale): array
    {
        return [
            'uuid' => $service->uuid,
            'name' => $service->name->get($locale),
            'short_description' => $service->short_description?->get($locale),
            'description' => $service->description?->get($locale),
            'duration_minutes' => $service->duration_minutes,
            'price' => $service->price($currency)->toArray($locale),
            'online_bookable' => $service->is_online_bookable,

            'category' => $service->relationLoaded('category') && $service->category !== null
                ? ['uuid' => $service->category->uuid, 'name' => $service->category->name->get($locale)]
                : null,

            'department' => $service->relationLoaded('department') && $service->department !== null
                ? ['uuid' => $service->department->uuid, 'name' => $service->department->name->get($locale)]
                : null,

            'images' => $service->relationLoaded('media')
                ? $service->media->map(fn (MediaItem $m): ?array => $this->image($m, $locale))
                    ->filter()->values()->all()
                : [],

            'variations' => $service->relationLoaded('variations')
                ? $service->variations->map(fn ($v): array => [
                    'uuid' => $v->uuid,
                    'name' => $v->name->get($locale),
                    // Resolved, not raw. A customer must never be shown a null
                    // price because a variation inherits (ADR-037).
                    'price' => $v->effectivePrice($service, $currency)->toArray($locale),
                    'duration_minutes' => $v->effectiveDurationMinutes($service),
                ])->values()->all()
                : [],

            'addons' => $service->relationLoaded('addons')
                ? $service->addons->map(fn ($a): array => [
                    'uuid' => $a->uuid,
                    'name' => $a->name->get($locale),
                    'price' => $a->price($currency)->toArray($locale),
                    'duration_minutes' => $a->duration_minutes,
                ])->values()->all()
                : [],
        ];
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

        // A private collection has no public URL by construction, so it simply
        // does not appear rather than emitting a link that would 403.
        return $url === null ? null : ['url' => $url, 'alt' => $item->alt_text?->get($locale)];
    }
}
