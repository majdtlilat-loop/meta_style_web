<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

/**
 * What the normalizer needs to know about THIS center, passed in rather than
 * looked up: the domain stays free of the database, and a test can state the
 * world it validates against.
 *
 *   primary   the center's primary content language — required text must
 *             exist in it (not in English: an Arabic-only center has none)
 *   locales   every language text may be kept in. Disabled languages keep
 *             their stored translations; only unknown locales are dropped.
 *   media     this center's site media: uuid => image|video
 *   refs      reference type => [uuid => still publicly usable]. A uuid that
 *             is not listed does not belong to this center and is refused.
 */
final readonly class SiteContext
{
    /**
     * @param  list<string>  $locales
     * @param  array<string, string>  $media
     * @param  array<string, array<string, bool>>  $refs
     */
    public function __construct(
        public string $primary,
        public array $locales,
        public array $media = [],
        public array $refs = [],
    ) {}

    public function mediaKind(string $uuid): ?string
    {
        return $this->media[$uuid] ?? null;
    }

    /**
     * Null when the uuid is not this center's; otherwise whether it is still
     * publicly usable (an archived service is known but no longer shown).
     */
    public function reference(string $type, string $uuid): ?bool
    {
        return $this->refs[$type][$uuid] ?? null;
    }
}
