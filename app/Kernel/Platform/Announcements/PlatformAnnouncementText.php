<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Announcements;

/**
 * The text of a platform announcement for an inbox line, in one language.
 *
 * Read from the control plane by uuid and memoised per request, so an inbox
 * listing twenty lines of the same announcement costs one query.
 */
final class PlatformAnnouncementText
{
    /** @var array<string, PlatformAnnouncement|null> */
    private static array $memo = [];

    public static function message(string $uuid, string $locale): ?string
    {
        if (! array_key_exists($uuid, self::$memo)) {
            /** @var PlatformAnnouncement|null $announcement */
            $announcement = PlatformAnnouncement::query()->where('uuid', $uuid)->first();
            self::$memo[$uuid] = $announcement;
        }

        $announcement = self::$memo[$uuid];
        if (! $announcement instanceof PlatformAnnouncement) {
            return null;
        }

        $title = $announcement->title->get($locale);
        $body = $announcement->body->get($locale);

        return trim($title.($body !== '' ? ' — '.$body : ''));
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
