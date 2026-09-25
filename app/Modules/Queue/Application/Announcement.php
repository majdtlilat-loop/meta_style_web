<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Modules\Queue\Domain\Models\QueueTicket;

/**
 * "Ticket A012, please proceed to Room 3." — as data, in several languages.
 *
 * ## A semantic payload, never a sentence built in PHP
 *
 * The server says WHAT to announce; the client says it. Building
 * `'Ticket '.$number.', please go to '.$destination` in PHP would hardcode
 * English word order into the backend and make Arabic and Kurdish
 * impossible to phrase properly — the sentence has to be a translation
 * template, and the values have to be substituted in the target language
 * (docs/07-LOCALIZATION.md, docs/17-QUEUE.md §16).
 *
 * The templates live in `lang/{en,ar,ckb}/queue_public.php` — named for the
 * SURFACE rather than the module, because a dotless `__('Queue')` is a
 * translation GROUP to Laravel, and on a case-insensitive filesystem that
 * resolves to `queue.php` and returns the whole array (docs/17-QUEUE.md §16).
 *
 * ## Kurdish Sorani, stated honestly
 *
 * `ckb` speech synthesis is effectively unavailable in mainstream browsers.
 * This class emits the ckb text anyway — the screen RENDERS it regardless of
 * whether anything can pronounce it — and the client speaks only the locales it
 * finds a voice for, falling back to a chime. Substituting an Arabic voice for
 * Kurdish text would be pretending, and a customer who needs Sorani would be
 * the one who found out (§16).
 *
 * ## No audio is generated or stored
 *
 * Playback belongs to the display client. There is no TTS service, no audio
 * files, no cache to invalidate and nothing to keep in step with a renamed
 * service point.
 */
final class Announcement
{
    /**
     * The payload for one call, in every language the display wants.
     *
     * @param  list<string>  $locales
     * @param  string|null  $key  the screen's opaque key for this call
     *                            ({@see DisplayFeed}); never the event's uuid
     * @return array<string, mixed>
     */
    public function forTicket(QueueTicket $ticket, array $locales, ?string $key): array
    {
        $ticket->loadMissing('servicePoint');

        $point = $ticket->servicePoint;

        $lines = [];

        foreach ($locales as $locale) {
            $destination = $point?->name->get($locale) ?? $point?->display_code;

            $lines[$locale] = $destination === null
                // No destination is a real case — a single-room barbershop has
                // nowhere to send anybody but "here".
                ? __('queue_public.announcement_short', ['number' => $ticket->display_number], $locale)
                : __('queue_public.announcement', [
                    'number' => $ticket->display_number,
                    'destination' => $destination,
                ], $locale);
        }

        return [
            'announcement_id' => $key,
            'number' => $ticket->display_number,
            'destination_code' => $point?->display_code,
            'lines' => $lines,
        ];
    }
}
