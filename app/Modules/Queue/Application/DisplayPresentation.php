<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Models\MediaItem;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueDisplayMedia;

/**
 * How a waiting-room screen LOOKS: its labels in every language it cycles, its
 * promotional media, and the timings — never what it shows about the queue.
 *
 * ## An ALLOW-LIST, like the feed
 *
 * This is read by an unauthenticated television. Every field is named here:
 * translated labels, the branch name, a media URL and its kind, and the
 * center's own captions and alt text as plain text. No uuid of any row, no
 * stored path, no file size, no staff name — the media URL is the generated,
 * unguessable file name the center media route serves (ADR-083), and it is the
 * only identifier an item has on the client (docs/17-QUEUE.md §9).
 *
 * ## Server-rendered strings, switched on the client
 *
 * A rotating screen swaps languages every few seconds WITHOUT reloading: the
 * page holds the strings of every language it cycles, from
 * `lang/{en,ar,ckb}/queue_public.php`, and flips `lang`/`dir` on the document.
 * Reloading a television every ten seconds would re-download everything and
 * lose the one touch that unlocked its sound. A Manager preview pinned to one
 * of the center's languages gets that language alone, rotating nothing
 * ({@see DisplayLanguages::forDisplay()}).
 *
 * ## Versioned, so a poll carries it only when it changed
 *
 * `version` is a digest of everything below. The feed a screen polls every
 * three seconds carries only the digest; the full presentation rides along only
 * when the screen's copy is stale — a caption edited, an item paused, a
 * language added — so a playlist change reaches the television within one poll
 * and costs nothing the rest of the day.
 */
final class DisplayPresentation
{
    /**
     * Every label the screen shows, per language. The page switches all of
     * them together, so a rotating screen is never half Arabic, half English.
     */
    private const TEXT = [
        'now_calling', 'recently_called', 'waiting', 'destination', 'thank_you',
        'state_called', 'state_serving', 'start', 'start_hint', 'controls',
        'fullscreen', 'exit_fullscreen', 'sample_call',
    ];

    public function __construct(
        private readonly DisplayLanguages $languages,
        private readonly LanguageRegistry $registry,
    ) {}

    /**
     * @param  string  $requested  the request's language, already one of the center's
     * @param  string|null  $pin  a Manager preview pinned to one of the center's languages:
     *                            that language's texts alone, never rotating
     * @return array{start: string, rotation: array{enabled: bool, seconds: int, locales: list<string>}, languages: array<string, array<string, mixed>>, promo: array{enabled: bool, slide_seconds: int, items: list<array<string, mixed>>}, version: string}
     */
    public function forDisplay(QueueDisplay $display, string $requested, ?string $pin = null): array
    {
        $display->loadMissing('branch');

        // Read on every poll: a screen with its media switched off pays nothing for it.
        if ($display->promo_enabled) {
            $display->loadMissing('promoMedia.mediaItem');
        }

        $languages = $this->languages->forDisplay($display, $requested, $pin);

        $labels = [];

        foreach ($languages['locales'] as $locale) {
            $text = [];

            foreach (self::TEXT as $key) {
                $text[$key] = (string) __('queue_public.'.$key, [], $locale);
            }

            $labels[$locale] = [
                'dir' => $this->registry->direction($locale),
                'label' => $this->registry->shortLabel($locale),
                'branch' => $display->branch?->name?->get($locale),
                'text' => $text,
            ];
        }

        $presentation = [
            'start' => $languages['start'],
            'rotation' => [
                'enabled' => $languages['rotate'],
                'seconds' => $languages['seconds'],
                'locales' => $languages['locales'],
            ],
            'languages' => $labels,
            'promo' => $this->promo($display, $languages['locales']),
        ];

        $presentation['version'] = substr(hash('sha256', (string) json_encode($presentation)), 0, 16);

        return $presentation;
    }

    /**
     * The playlist a screen plays: enabled items with a servable file, in order.
     *
     * @param  list<string>  $locales
     * @return array{enabled: bool, slide_seconds: int, items: list<array<string, mixed>>}
     */
    private function promo(QueueDisplay $display, array $locales): array
    {
        $items = [];

        if ($display->promo_enabled) {
            foreach ($display->promoMedia as $row) {
                $item = $this->item($row, $locales);

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return [
            // "On" with nothing playable is off: the queue keeps the whole
            // screen rather than framing an empty panel.
            'enabled' => $items !== [],
            'slide_seconds' => $display->slideSeconds(),
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, mixed>|null
     */
    private function item(QueueDisplayMedia $row, array $locales): ?array
    {
        $media = $row->mediaItem;

        if (! $row->is_enabled || ! $media instanceof MediaItem) {
            return null;
        }

        $url = $media->url();
        $mime = $media->mime_type;

        // Only what the media kernel accepted and the center route serves.
        if ($url === null || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'], true)) {
            return null;
        }

        $video = str_starts_with($mime, 'video/');

        return [
            'kind' => $video ? 'video' : 'image',
            'url' => $url,
            'type' => $mime,
            'caption' => $this->texts($row->caption, $locales),
            // A video has no alt text; its caption, if any, is its words.
            'alt' => $video ? $this->empty($locales) : $this->texts($media->alt_text, $locales),
        ];
    }

    /**
     * One string per screen language, falling back like every translatable
     * field (docs/07-LOCALIZATION.md §5.1) — a caption written only in Arabic
     * still shows while the screen is in English, rather than vanishing.
     *
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function texts(?TranslatedText $text, array $locales): array
    {
        $out = [];

        foreach ($locales as $locale) {
            $out[$locale] = $text instanceof TranslatedText ? $text->get($locale) : '';
        }

        return $out;
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function empty(array $locales): array
    {
        return array_fill_keys($locales, '');
    }
}
