<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of the inbox.
 *
 * Every listener, every sweep and every command goes through here, so "what
 * gets written, and who sees it" is decided once (docs/23-NOTIFICATIONS.md §6).
 *
 * ## Delivered twice is delivered once
 *
 * Nothing asks "have I already sent this?" — that question races with itself.
 * Instead both inserts are `INSERT ... ON DUPLICATE KEY IGNORE` against real
 * unique indexes: `notifications(type, source_type, source_uuid)` and
 * `notification_recipients(notification_id, recipient_kind, recipient_id)`. The
 * same appointment heard twice, a sweep that overlaps itself and a
 * reconciliation pass all converge on one row and one inbox line, whichever of
 * them gets there first (§12).
 *
 * ## The audience belongs to the type
 *
 * A staff-only alert can never be addressed to a customer, because the check is
 * `NotificationType::audience()` and not something each call site remembers.
 * The combination that leaks a manager's alert into a customer's inbox is not
 * reachable from here.
 *
 * ## It never throws at the source
 *
 * Callers run this after their own transaction has committed, and a failure is
 * reported and dropped (`AfterCommit`). A booking, a payment, an activation or
 * a review is never lost because the center could not be told about it (§11).
 */
final class NotificationCenter
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * Writes the notification and its inbox rows. Returns how many people it
     * actually reached — 0 when everything was already delivered.
     */
    public function deliver(NotificationRequest $request, ?CarbonImmutable $now = null): int
    {
        $recipients = $this->eligible($request);

        if ($recipients === []) {
            return 0;
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var int $delivered */
        $delivered = DB::connection('tenant')->transaction(function () use ($request, $recipients, $at): int {
            $notification = $this->fact($request, $at);

            if (! $notification instanceof Notification) {
                return 0;
            }

            $rows = [];

            foreach ($recipients as $recipient) {
                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'notification_id' => $notification->getKey(),
                    'recipient_kind' => $recipient->kind->value,
                    'recipient_id' => $recipient->id,
                    'read_at' => null,
                    'created_at' => $at,
                ];
            }

            $before = NotificationRecipient::query()->where('notification_id', $notification->getKey())->count();

            NotificationRecipient::query()->insertOrIgnore($rows);

            return NotificationRecipient::query()->where('notification_id', $notification->getKey())->count() - $before;
        });

        return $delivered;
    }

    /**
     * The fact row, created or found. `insertOrIgnore` then read: two workers
     * racing here both end up with the same row rather than one of them
     * failing on the unique index.
     */
    private function fact(NotificationRequest $request, CarbonImmutable $at): ?Notification
    {
        Notification::query()->insertOrIgnore([[
            'uuid' => (string) Str::uuid(),
            'type' => $request->type->value,
            'severity' => $request->type->severity()->value,
            'source_type' => $request->sourceType,
            'source_uuid' => $request->sourceUuid,
            'branch_id' => $request->branchId,
            'params' => json_encode($request->params, JSON_THROW_ON_ERROR),
            'created_at' => $at,
        ]]);

        /** @var Notification|null $notification */
        $notification = Notification::query()
            ->where('type', $request->type->value)
            ->where('source_type', $request->sourceType)
            ->where('source_uuid', $request->sourceUuid)
            ->first();

        return $notification;
    }

    /**
     * The recipients that are both the right KIND for this type and have not
     * switched it off.
     *
     * @return list<Recipient>
     */
    private function eligible(NotificationRequest $request): array
    {
        $audience = $request->type->audience();
        $eligible = [];
        $seen = [];

        foreach ($request->recipients as $recipient) {
            if ($recipient->kind !== $audience || $recipient->id < 1) {
                continue;
            }

            if (isset($seen[$recipient->key()])) {
                continue;
            }

            $seen[$recipient->key()] = true;

            if (! $this->preferences->allows($recipient, $request->type)) {
                continue;
            }

            $eligible[] = $recipient;
        }

        return $eligible;
    }
}
