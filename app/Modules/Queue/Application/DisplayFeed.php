<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
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
 * `announcement_id` is the uuid of the call event. A poll that sees the same
 * ids says nothing; a recall writes a new event with a new uuid and the screen
 * speaks again. That is the whole mechanism, and it is why the browser needs no
 * memory beyond "which ids have I already spoken" (correction 2).
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forDisplay(QueueDisplay $display, ?CarbonImmutable $now = null): array
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

        $locale = $display->locale ?? app()->getLocale();

        return [
            'display' => [
                'name' => $display->name,
                'branch_name' => $branch?->name?->get($locale),
                'locale' => $locale,
                'direction' => $this->languages->direction($locale),
                'sound_enabled' => $display->sound_enabled,
                'voice_enabled' => $this->mayAnnounce($display),
                'voice_locales' => $display->voiceLocales(),
                'recent_limit' => $limit,
            ],
            // The big number at the top: the most recent call.
            'now_calling' => $tickets === [] ? null : $this->line($tickets[0], $locale),
            'recent' => array_map(
                fn (QueueTicket $ticket): array => $this->line($ticket, $locale),
                array_slice($tickets, 1, $limit),
            ),
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
     * One row on the screen.
     *
     * @return array<string, mixed>
     */
    private function line(QueueTicket $ticket, string $locale): array
    {
        $point = $ticket->servicePoint;

        return [
            /*
             * The stable identifier a browser uses to decide whether it has
             * already spoken this call. Null only for a ticket whose calls
             * predate the column, which cannot happen in a fresh install but
             * must not make the screen unrenderable.
             */
            'announcement_id' => $ticket->last_announcement_uuid,
            'number' => $ticket->display_number,
            'destination_code' => $point?->display_code,
            'destination_name' => $point?->name->get($locale),
            'department_name' => $ticket->department?->name->get($locale),
            'called_at' => $ticket->last_called_at?->toIso8601String(),
            // Whether this one is still the current call, so a screen can dim
            // the rest without knowing the queue's state machine.
            'state' => $ticket->state->value,
        ];
    }
}
