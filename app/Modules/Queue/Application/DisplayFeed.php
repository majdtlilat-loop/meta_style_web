<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Time\BranchClock;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicket;
use Carbon\CarbonImmutable;

/**
 * What a television shows, and nothing else.
 *
 * ## An ALLOW-LIST, field by field
 *
 * This is a guest surface with no login, pointed at a screen in a waiting room.
 * Every field emitted is named here. Never `$model->toArray()` minus a
 * deny-list — the next column somebody adds would be published by default, and
 * on this module that column is somebody's phone number
 * (docs/08-AUDIT-SECURITY.md, docs/17-QUEUE.md §14).
 *
 * What a customer sees is a NUMBER and a DESTINATION. Not their name, not
 * anybody else's, no phone, no email, no notes, no employee identity, no
 * capacity, no internal ids, no audit fields.
 *
 * ## The announcement identifier
 *
 * The screen polls every few seconds. Without a value that changes only when
 * something new was SAID, it would either repeat the same number forever or
 * have to guess from timestamps.
 *
 * `announcement_id` stands for the call event: a poll that sees the same ids
 * says nothing; a recall writes a new event and the screen speaks again. That
 * is the whole mechanism, and it is why the browser needs no memory beyond
 * "which ids have I already spoken" (correction 2).
 *
 * It is never the event's uuid. Every `announcement_id` on the wire — each
 * line's, and the speech payload's — is the same keyed digest as `call_key`
 * below, so a public screen publishes no internal identifier (§13).
 *
 * ## The new-call key, for every screen
 *
 * The speech payload goes only to a screen that may SPEAK, but a screen with
 * its chime on and its voice off (or without `queue_voice`) still has to know
 * that somebody new was called. `call_key` answers that for every screen: a
 * keyed digest of the current call event, scoped to this screen, present
 * whenever a call is on screen and changing only when a call or a recall
 * writes a new event. The screen chimes once per key; a poll that sees the
 * same key, and a language switch, do nothing. It is not an id: a digest of the
 * event under `APP_KEY` and the screen names nothing and correlates nothing
 * across screens (§13, §16).
 *
 * ## Bounded, always
 *
 * One branch, one local day, at most `recent_calls_limit` rows — capped at
 * {@see QueueDisplay::MAX_RECENT} whatever the configuration says. A screen left
 * on all day must never come to read a whole day of tickets every three seconds
 * (§19).
 *
 * ## The database is the source of truth
 *
 * This read is the canonical state. There is no event stream to catch up on and
 * nothing to replay: a screen that loses its network for an hour recovers
 * completely on its next successful poll, which is what makes correctness
 * independent of delivery (§49).
 */
final class DisplayFeed
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly LanguageRegistry $languages,
        private readonly DisplayLanguages $displayLanguages,
        private readonly Announcement $announcements,
    ) {}

    /**
     * @param  string|null  $pin  a Manager preview pinned to one of the center's
     *                            languages ({@see DisplayLanguages::pinnable()})
     * @return array<string, mixed>
     */
    public function forDisplay(QueueDisplay $display, ?CarbonImmutable $now = null, ?string $pin = null): array
    {
        // The display entitlement, separate from queue management: a center may
        // run a queue at the desk without paying for screens (§19).
        $this->entitlements->ensure('queue_display');

        $display->loadMissing(['branch', 'department', 'servicePoint']);

        $branch = $display->branch;
        $at = ($now ?? CarbonImmutable::now())->utc();
        $timezone = $branch->timezone ?? 'UTC';

        $limit = $display->recentLimit();

        $query = QueueTicket::query()
            ->with(['servicePoint', 'department'])
            ->where('branch_id', $display->branch_id)
            ->where('business_date', BranchClock::localDate($at, $timezone))
            // Only tickets somebody has actually called. A waiting list on a
            // public screen tells a room how long its wait is going to be, which
            // is a product decision nobody has made.
            ->whereNotNull('last_called_at')
            ->orderByDesc('last_called_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($display->department_id !== null) {
            $query->where('department_id', $display->department_id);
        }

        if ($display->service_point_id !== null) {
            $query->where('service_point_id', $display->service_point_id);
        }

        /** @var list<QueueTicket> $tickets */
        $tickets = $query->get()->all();

        // Only languages the center publishes in; a rotating screen gets its
        // destinations named in every language it cycles (§9).
        $screen = $this->displayLanguages->forDisplay($display, app()->getLocale(), $pin);
        $locale = $screen['start'];
        $locales = $screen['locales'];

        return [
            'display' => [
                'name' => $display->name,
                'branch_name' => $branch?->name?->get($locale),
                'locale' => $locale,
                'direction' => $this->languages->direction($locale),
                'languages' => $locales,
                'sound_enabled' => $display->sound_enabled,
                'voice_enabled' => $this->mayAnnounce($display),
                'voice_locales' => $display->voiceLocales(),
                'recent_limit' => $limit,
            ],
            // The big number at the top: the most recent call.
            'now_calling' => $tickets === [] ? null : $this->line($display, $tickets[0], $locale, $locales),
            // "Somebody new was called", for every screen, voice or not.
            'call_key' => $tickets === [] ? null : $this->callKey($display, $tickets[0]),
            'recent' => array_map(
                fn (QueueTicket $ticket): array => $this->line($display, $ticket, $locale, $locales),
                array_slice($tickets, 1, $limit),
            ),
            /*
             * The words, for the CURRENT call only, and only when the screen
             * MAY speak. A silent screen carries no speech payload at all:
             * nothing for a browser to read out, and nothing extra on the wire
             * every three seconds (§16, §19).
             */
            'announcement' => $tickets !== [] && $this->mayAnnounce($display)
                ? $this->announcements->forTicket($tickets[0], $display->voiceLocales(), $this->callKey($display, $tickets[0]))
                : null,
            'server_time' => $at->toIso8601String(),
        ];
    }

    /**
     * May this screen SPEAK?
     *
     * Two independent answers, and both have to be yes: the center bought
     * `queue_voice`, and this particular screen is configured to use it. A
     * waiting room in a hospital wants silence at night whatever the
     * subscription says, and a center that never bought voice gets none however
     * the screen is configured (§16, §19).
     *
     * Public because the feed is where the entitlement question belongs, and
     * the controller assembling the speech payload must not restate it — a
     * second copy of an authorization rule is a rule that eventually disagrees
     * with itself.
     */
    public function mayAnnounce(QueueDisplay $display): bool
    {
        return $display->voice_enabled && $this->entitlements->enabled('queue_voice');
    }

    /**
     * The opaque key of a call: a keyed digest of the call event, scoped to
     * this screen — `call_key`, and every `announcement_id` on the wire. Null
     * only for a call that predates the announcement column.
     */
    private function callKey(QueueDisplay $display, QueueTicket $ticket): ?string
    {
        $event = $ticket->last_announcement_uuid;

        return $event === null ? null : Fingerprint::of('queue-call|'.$display->uuid.'|'.$event);
    }

    /**
     * One row on the screen.
     *
     * @param  list<string>  $locales  every language the screen shows
     * @return array<string, mixed>
     */
    private function line(QueueDisplay $display, QueueTicket $ticket, string $locale, array $locales): array
    {
        $point = $ticket->servicePoint;

        $names = [];

        foreach ($locales as $language) {
            $names[$language] = $point?->name->get($language);
        }

        return [
            /*
             * The stable key a browser uses to decide whether it has already
             * shown this call — the opaque digest, never the event's uuid.
             * Null only for a ticket whose calls predate the column, which
             * cannot happen in a fresh install but must not make the screen
             * unrenderable.
             */
            'announcement_id' => $this->callKey($display, $ticket),
            'number' => $ticket->display_number,
            'destination_code' => $point?->display_code,
            'destination_name' => $point?->name->get($locale),
            // The same destination in each language the screen rotates
            // through, so a language switch is a relabel on the client and
            // never another request. Names only — never an id.
            'destination_names' => $names,
            'department_name' => $ticket->department?->name->get($locale),
            'called_at' => $ticket->last_called_at?->toIso8601String(),
            // Whether this one is still the current call, so a screen can dim
            // the rest without knowing the queue's state machine.
            'state' => $ticket->state->value,
        ];
    }
}
