<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;

/**
 * The center's site and brand media libraries, read by uuid.
 *
 * Content never holds a path or a URL — only a uuid — and a uuid resolves ONLY
 * inside this center's own `media_items` and only under the site or brand
 * owner. Another center's uuid, or a catalog photo's, is simply not found.
 */
final class SiteMedia
{
    /** The site and the brand are center-wide owners with one owner row. */
    public const OWNER_ID = 1;

    /**
     * Every site media uuid of this center and its kind — what the content
     * normalizer accepts.
     *
     * @return array<string, string> uuid => image|video
     */
    public function kinds(): array
    {
        $kinds = [];
        foreach (MediaItem::query()->for(MediaOwner::Site, self::OWNER_ID)->get(['id', 'uuid', 'mime_type']) as $item) {
            $kinds[$item->uuid] = str_starts_with($item->mime_type, 'video/') ? 'video' : 'image';
        }

        return $kinds;
    }

    /**
     * Public URLs for the given uuids, in one query.
     *
     * @param  list<string>  $uuids
     * @return array<string, array{url: string, kind: string, width: int|null, height: int|null}>
     */
    public function resolve(array $uuids, MediaOwner $owner = MediaOwner::Site): array
    {
        $uuids = array_values(array_unique(array_filter($uuids, static fn (string $uuid): bool => $uuid !== '')));
        if ($uuids === []) {
            return [];
        }

        $resolved = [];
        foreach (MediaItem::query()->for($owner, self::OWNER_ID)->whereIn('uuid', $uuids)->get() as $item) {
            $url = $item->url();
            if ($url !== null) {
                $resolved[$item->uuid] = [
                    'url' => $url,
                    'kind' => str_starts_with($item->mime_type, 'video/') ? 'video' : 'image',
                    'width' => $item->width,
                    'height' => $item->height,
                ];
            }
        }

        return $resolved;
    }

    /**
     * Every site media uuid a content document references, wherever it sits.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function referencedBy(array $content): array
    {
        $uuids = [];
        array_walk_recursive($content, static function (mixed $value, int|string $key) use (&$uuids): void {
            if (in_array($key, ['image', 'video', 'poster', 'og_image'], true) && is_string($value) && $value !== '') {
                $uuids[] = $value;
            }
        });

        return array_values(array_unique($uuids));
    }
}
