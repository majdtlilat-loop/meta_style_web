<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Ordering;

use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use LogicException;

/**
 * The library's current order, as read under the ordering lock.
 *
 * Only {@see CatalogOrdering::lock()} builds one. Mutations happen in memory
 * and {@see persist()} writes the rows whose position actually changed:
 * categories 0..n-1, services 0..N-1 across the whole library (categories in
 * order, uncategorised last). Archived rows take no part and keep whatever
 * value they had; restoring one appends it again.
 */
final class CatalogLayout
{
    /** The group key for services with no (live) category. */
    public const UNCATEGORISED = 0;

    private const MAX_POSITION = 65535;

    /** @var list<int> category ids, in order */
    private array $categories = [];

    /** @var array<int, string> category id => uuid */
    private array $categoryUuids = [];

    /** @var array<int, int> category id => stored sort_order */
    private array $categoryStored = [];

    /** @var array<int, list<int>> group key => service ids, in order */
    private array $groups = [];

    /** @var array<int, int> service id => stored sort_order */
    private array $serviceStored = [];

    /**
     * @param  list<ServiceCategory>  $categories  non-archived, in (sort_order, id) order
     * @param  list<Service>  $services  non-archived, in (sort_order, id) order
     */
    public function __construct(array $categories, array $services)
    {
        foreach ($categories as $category) {
            $id = (int) $category->id;
            $this->categories[] = $id;
            $this->categoryUuids[$id] = (string) $category->uuid;
            $this->categoryStored[$id] = (int) $category->sort_order;
            $this->groups[$id] = [];
        }

        $this->groups[self::UNCATEGORISED] = [];

        foreach ($services as $service) {
            $categoryId = $service->service_category_id;
            // A service pointing at an archived category is shown as
            // uncategorised, so it is ordered with them.
            $group = $categoryId !== null && isset($this->groups[$categoryId]) ? (int) $categoryId : self::UNCATEGORISED;

            $this->groups[$group][] = (int) $service->id;
            $this->serviceStored[(int) $service->id] = (int) $service->sort_order;
        }
    }

    // ------------------------------------------------------------ categories

    public function categoryIndex(int $categoryId): ?int
    {
        $index = array_search($categoryId, $this->categories, true);

        return $index === false ? null : $index;
    }

    public function categoryCount(): int
    {
        return count($this->categories);
    }

    /**
     * Moves a live category to a position, clamped to the list.
     *
     * @return array{0: int, 1: int} from, to
     */
    public function moveCategory(int $categoryId, int $toIndex): array
    {
        $from = $this->categoryIndex($categoryId) ?? throw new LogicException('That category is not in the live library.');
        $to = $this->clamp($toIndex, count($this->categories) - 1);

        array_splice($this->categories, $from, 1);
        array_splice($this->categories, $to, 0, [$categoryId]);

        return [$from, $to];
    }

    /**
     * Adds a category (new or restored) at the end of the list.
     */
    public function appendCategory(ServiceCategory $category): void
    {
        $id = (int) $category->id;

        if ($this->categoryIndex($id) !== null) {
            $this->moveCategory($id, count($this->categories) - 1);

            return;
        }

        $this->categories[] = $id;
        $this->categoryUuids[$id] = (string) $category->uuid;
        $this->categoryStored[$id] = (int) $category->sort_order;
        $this->groups[$id] = [];
    }

    /**
     * Takes a category out of the live list; its services join the end of
     * the uncategorised group, in their current order.
     */
    public function removeCategory(int $categoryId): void
    {
        $index = $this->categoryIndex($categoryId);

        if ($index === null) {
            return;
        }

        array_splice($this->categories, $index, 1);

        $this->groups[self::UNCATEGORISED] = [
            ...$this->groups[self::UNCATEGORISED],
            ...$this->groups[$categoryId],
        ];

        unset($this->groups[$categoryId], $this->categoryUuids[$categoryId], $this->categoryStored[$categoryId]);
    }

    public function categoryUuid(int $group): ?string
    {
        return $this->categoryUuids[$group] ?? null;
    }

    // -------------------------------------------------------------- services

    public function hasGroup(int $group): bool
    {
        return isset($this->groups[$group]);
    }

    public function groupSize(int $group): int
    {
        return count($this->groups[$group] ?? []);
    }

    /**
     * Where a live service sits, or null when it is archived or unknown.
     *
     * @return array{0: int, 1: int}|null group, index
     */
    public function locateService(int $serviceId): ?array
    {
        foreach ($this->groups as $group => $ids) {
            $index = array_search($serviceId, $ids, true);

            if ($index !== false) {
                return [$group, $index];
            }
        }

        return null;
    }

    /**
     * @return list<int> the service ids of one group, in order
     */
    public function servicesIn(int $group): array
    {
        return $this->groups[$group] ?? [];
    }

    /**
     * Puts a service at a position of a group — `null` means the end.
     *
     * The service may be new to the layout (just created, just restored) or
     * already in it, in which case it is taken out of wherever it was first.
     *
     * @return array{from: array{0: int, 1: int}|null, to: array{0: int, 1: int}}
     */
    public function placeService(Service $service, int $group, ?int $toIndex = null): array
    {
        if (! $this->hasGroup($group)) {
            throw new LogicException('That category is not in the live library.');
        }

        $id = (int) $service->id;
        $from = $this->locateService($id);

        if ($from !== null) {
            array_splice($this->groups[$from[0]], $from[1], 1);
        } else {
            $this->serviceStored[$id] = (int) $service->sort_order;
        }

        $size = count($this->groups[$group]);
        $to = $toIndex === null ? $size : $this->clamp($toIndex, $size);

        array_splice($this->groups[$group], $to, 0, [$id]);

        return ['from' => $from, 'to' => [$group, $to]];
    }

    /**
     * Puts a service directly after another one, in the other one's group.
     */
    public function placeServiceAfter(Service $service, Service $anchor): void
    {
        $at = $this->locateService((int) $anchor->id) ?? throw new LogicException('The anchor service is not in the live library.');

        $this->placeService($service, $at[0], $at[1] + 1);
    }

    // ---------------------------------------------------------------- write

    /**
     * Writes every position that changed, and nothing else.
     */
    public function persist(): void
    {
        foreach ($this->categories as $position => $id) {
            $position = min($position, self::MAX_POSITION);

            if (($this->categoryStored[$id] ?? -1) !== $position) {
                ServiceCategory::query()->whereKey($id)->update(['sort_order' => $position]);
                $this->categoryStored[$id] = $position;
            }
        }

        $position = 0;

        foreach ([...$this->categories, self::UNCATEGORISED] as $group) {
            foreach ($this->groups[$group] as $id) {
                $value = min($position, self::MAX_POSITION);

                if (($this->serviceStored[$id] ?? -1) !== $value) {
                    Service::query()->whereKey($id)->update(['sort_order' => $value]);
                    $this->serviceStored[$id] = $value;
                }

                $position++;
            }
        }
    }

    private function clamp(int $index, int $max): int
    {
        return max(0, min($index, max(0, $max)));
    }
}
