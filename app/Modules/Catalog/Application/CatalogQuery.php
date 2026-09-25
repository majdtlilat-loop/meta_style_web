<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the service library for the Manager: categories with their counts,
 * and services filtered the way the library page filters them.
 *
 * A bounded number of queries whatever the catalog's size: categories with a
 * live-service count and their image in two, the services with category,
 * department, variations and images in five.
 *
 * The name search runs over the loaded names rather than in SQL: names are
 * JSON, and a LIKE on the column also matches the language KEYS — "ar" would
 * find every service with an Arabic name. A center's library is hundreds of
 * rows, not millions, so matching in PHP is both correct and cheap.
 */
final class CatalogQuery
{
    public const STATUSES = ['', 'active', 'inactive', 'archived'];

    public const VISIBILITIES = ['', 'public', 'hidden', 'online', 'offline'];

    public function authorize(User $viewer): void
    {
        if (! $viewer->hasPermission(Permission::ServiceView)) {
            throw new AuthorizationException('You may not view services.');
        }
    }

    /**
     * Live (non-archived) categories, active or not, in library order.
     *
     * @return Collection<int, ServiceCategory>
     */
    public function categories(User $viewer): Collection
    {
        $this->authorize($viewer);

        return ServiceCategory::query()
            ->whereNull('archived_at')
            ->withCount(['services as live_services_count' => fn (Builder $q) => $q->whereNull('archived_at')])
            ->with('media')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ServiceCategory>
     */
    public function archivedCategories(User $viewer): Collection
    {
        $this->authorize($viewer);

        return ServiceCategory::query()
            ->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->get();
    }

    public function category(string $uuid, User $viewer): ?ServiceCategory
    {
        $this->authorize($viewer);

        return ServiceCategory::query()->where('uuid', $uuid)->first();
    }

    /**
     * How many services each status holds, inside one category scope.
     *
     * @param  string|null  $category  null = all, 'none' = uncategorised, else a category uuid
     * @return array{live: int, active: int, inactive: int, archived: int, uncategorised: int}
     */
    public function counts(?string $category, User $viewer): array
    {
        $this->authorize($viewer);

        $query = Service::query();
        $this->scopeCategory($query, $category);

        $row = $query->toBase()->selectRaw(
            'SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) AS live,'
            .' SUM(CASE WHEN archived_at IS NULL AND is_active = 1 THEN 1 ELSE 0 END) AS active,'
            .' SUM(CASE WHEN archived_at IS NULL AND is_active = 0 THEN 1 ELSE 0 END) AS inactive,'
            .' SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived'
        )->first();

        $uncategorised = Service::query()->whereNull('archived_at');
        $this->scopeCategory($uncategorised, 'none');

        return [
            'live' => (int) ($row->live ?? 0),
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
            'archived' => (int) ($row->archived ?? 0),
            'uncategorised' => $uncategorised->count(),
        ];
    }

    /**
     * @param  array{category?: string|null, search?: string, status?: string, visibility?: string}  $filters
     * @return Collection<int, Service>
     */
    public function services(array $filters, User $viewer): Collection
    {
        $this->authorize($viewer);

        $query = Service::query()->with(['category', 'department', 'variations', 'media']);

        $this->scopeCategory($query, $filters['category'] ?? null);

        $status = $filters['status'] ?? '';

        match ($status) {
            'active' => $query->whereNull('archived_at')->where('is_active', true),
            'inactive' => $query->whereNull('archived_at')->where('is_active', false),
            'archived' => $query->whereNotNull('archived_at'),
            default => $query->whereNull('archived_at'),
        };

        match ($filters['visibility'] ?? '') {
            'public' => $query->where('is_public', true),
            'hidden' => $query->where('is_public', false),
            'online' => $query->where('is_online_bookable', true),
            'offline' => $query->where('is_online_bookable', false),
            default => null,
        };

        if ($status === 'archived') {
            $query->orderByDesc('archived_at')->orderByDesc('id');
        } else {
            $query->orderBy('sort_order')->orderBy('id');
        }

        $services = $query->get();

        $needle = mb_strtolower(trim($filters['search'] ?? ''));

        if ($needle === '') {
            return $services;
        }

        return $services->filter(function (Service $service) use ($needle): bool {
            foreach ($service->name->all() as $text) {
                if (str_contains(mb_strtolower($text), $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @param  Builder<Service>  $query
     */
    private function scopeCategory(Builder $query, ?string $category): void
    {
        if ($category === null || $category === '') {
            return;
        }

        if ($category === 'none') {
            // A service still pointing at an archived category is shown with
            // the uncategorised ones, as the menu shows it.
            $query->where(function (Builder $q): void {
                $q->whereNull('service_category_id')
                    ->orWhereIn('service_category_id', ServiceCategory::query()->whereNotNull('archived_at')->select('id'));
            });

            return;
        }

        $query->whereIn('service_category_id', ServiceCategory::query()->where('uuid', $category)->select('id'));
    }
}
