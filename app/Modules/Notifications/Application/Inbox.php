<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Exceptions\NotificationsFailed;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One person's own notifications, and nobody else's.
 *
 * ## Every query filters on BOTH halves of the identity
 *
 * `recipient_kind` AND `recipient_id`. Staff user 7 and customer account 7 are
 * different people in different tables, so a query that filtered on the id
 * alone would hand one of them the other's inbox. The recipient is taken from
 * the guard by the caller and never from a request body
 * (docs/23-NOTIFICATIONS.md §4).
 *
 * There is no "read somebody else's inbox" path, for staff or for anyone —
 * not a permission, not an admin view. A notification is addressed mail.
 *
 * ## Two queries, both indexed
 *
 * The page and the unread count each run once against
 * `notification_recipients_inbox_index` (kind, id, read_at). Neither counts in
 * PHP, and neither loads the whole history to answer "how many unread"
 * (§§17–18).
 */
final class Inbox
{
    /** A page of an inbox. Enough to scroll, not enough to be a report. */
    public const PAGE = 20;

    public function __construct(private readonly NotificationPresenter $presenter) {}

    /**
     * Newest first. `$before` is the uuid of the last row already shown.
     *
     * @return list<array<string, mixed>>
     */
    public function page(Recipient $recipient, int $limit = self::PAGE, ?string $before = null): array
    {
        $query = $this->mine($recipient)->with('notification')->orderByDesc('id')->limit(max(1, min($limit, 100)));

        if ($before !== null) {
            $cursor = $this->mine($recipient)->where('uuid', $before)->value('id');

            if ($cursor !== null) {
                $query->where('id', '<', $cursor);
            }
        }

        return $this->presenter->collection($query->get());
    }

    /**
     * Unread only, newest first — the same cursor as {@see self::page()}.
     *
     * @return list<array<string, mixed>>
     */
    public function unreadPage(Recipient $recipient, int $limit = self::PAGE, ?string $before = null): array
    {
        $query = $this->mine($recipient)->whereNull('read_at')->with('notification')->orderByDesc('id')->limit(max(1, min($limit, 100)));

        if ($before !== null) {
            $cursor = $this->mine($recipient)->where('uuid', $before)->value('id');

            if ($cursor !== null) {
                $query->where('id', '<', $cursor);
            }
        }

        return $this->presenter->collection($query->get());
    }

    public function unreadCount(Recipient $recipient): int
    {
        return $this->mine($recipient)->whereNull('read_at')->count();
    }

    /**
     * The newest unread row's id (0 when there is none), so the bell can tell
     * "something new arrived" from "the same unread as before". One indexed
     * MAX over (kind, id, read_at). An internal id: callers keep it on the
     * server (the session), never in a page.
     */
    public function latestUnreadId(Recipient $recipient): int
    {
        return (int) $this->mine($recipient)->whereNull('read_at')->max('id');
    }

    /**
     * Marks one as read. Already-read is not an error; somebody else's is not
     * found, which is the same answer they would get for one that never
     * existed (§16).
     *
     * @throws NotificationsFailed
     */
    public function markRead(Recipient $recipient, string $uuid, ?CarbonImmutable $now = null): void
    {
        /** @var NotificationRecipient|null $row */
        $row = $this->mine($recipient)->where('uuid', $uuid)->first();

        if (! $row instanceof NotificationRecipient) {
            throw NotificationsFailed::notYours();
        }

        if ($row->read_at !== null) {
            return;
        }

        $row->forceFill(['read_at' => ($now ?? CarbonImmutable::now())->utc()])->save();
    }

    /**
     * Marks everything unread as read. Returns how many it changed.
     */
    public function markAllRead(Recipient $recipient, ?CarbonImmutable $now = null): int
    {
        return $this->mine($recipient)
            ->whereNull('read_at')
            ->update(['read_at' => ($now ?? CarbonImmutable::now())->utc()]);
    }

    /**
     * The one place the recipient filter is written.
     *
     * @return Builder<NotificationRecipient>
     */
    private function mine(Recipient $recipient): Builder
    {
        return NotificationRecipient::query()
            ->where('recipient_kind', $recipient->kind->value)
            ->where('recipient_id', $recipient->id);
    }
}
