<?php

declare(strict_types=1);

namespace App\Kernel\Media;

/**
 * What a media item is attached to.
 *
 * A stable STRING key, not a model class name. Laravel's default polymorphic
 * column stores `App\Modules\Catalog\Domain\Models\Service`; moving that class
 * to another namespace then silently orphans every row pointing at it, turning
 * an ordinary refactor into a data migration across every tenant database.
 * These values never change (docs/09-STORAGE.md §5).
 *
 * Only owners that exist. Adding `customer` in Phase 5 is one case here.
 */
enum MediaOwner: string
{
    case Branch = 'branch';
    case Department = 'department';
    case ServiceCategory = 'service_category';
    case Service = 'service';

    /**
     * How many images this owner may hold.
     *
     * A service gets a gallery — customers want to see the work. Everything
     * else gets one image, because a department with six photographs is a menu
     * nobody can scan.
     */
    public function maxItems(): int
    {
        return match ($this) {
            self::Service => (int) config('metastyle.catalog.media.max_images_per_service', 8),
            default => 1,
        };
    }

    public function allowsMultiple(): bool
    {
        return $this->maxItems() > 1;
    }
}
