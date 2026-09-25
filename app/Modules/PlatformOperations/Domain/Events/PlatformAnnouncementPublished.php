<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Domain\Events;

/**
 * The Super Admin published an announcement, and it has reached one center.
 *
 * Dispatched INSIDE that center's tenant context, once per center, so a
 * listener delivers it to that center's own staff and nobody else's.
 */
final readonly class PlatformAnnouncementPublished
{
    public function __construct(
        public string $announcementUuid,
        public bool $important,
    ) {}
}
