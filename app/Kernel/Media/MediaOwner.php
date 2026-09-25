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
     * The center's own brand assets: logo (light and dark) and favicon. One
     * owner row per center (owner id 1) — the brand is a center setting, not a
     * record with an id of its own.
     */
    case Brand = 'brand';

    /**
     * Imagery and video used by the center's public site (hero, sections,
     * gallery, team photos, social image). Owner id 1, like the brand. Site
     * content references these by uuid; removing one from a page only
     * unreferences it, because a published or archived version may still
     * point at it.
     */
    case Site = 'site';

    /**
     * The promotional images and videos ONE waiting-room screen plays beside
     * its queue. Owner id = that screen's row, so a branch-limited manager
     * only ever touches the screens of their own branches.
     */
    case QueueDisplay = 'queue_display';

    /**
     * How many images this owner may hold.
     *
     * A service gets a gallery — customers want to see the work. Everything
     * else gets one image, because a department with six photographs is a menu
     * nobody can scan. The brand holds its three slots plus room for a
     * replacement to land before the old file is removed; the site library is
     * bounded by configuration.
     */
    public function maxItems(): int
    {
        return match ($this) {
            self::Service => (int) config('metastyle.catalog.media.max_images_per_service', 8),
            self::Brand => 6,
            self::Site => (int) config('site.media.max_items', 300),
            // A screen's playlist: enough for a rotation, small enough that a
            // television never preloads a library.
            self::QueueDisplay => 12,
            default => 1,
        };
    }

    public function allowsMultiple(): bool
    {
        return $this->maxItems() > 1;
    }
}
