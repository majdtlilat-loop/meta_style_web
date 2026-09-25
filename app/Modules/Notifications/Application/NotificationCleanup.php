<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Enums\NotificationSeverity;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use Carbon\CarbonImmutable;

/**
 * Notifications do not live for ever.
 *
 * ## They are communication history, not domain truth
 *
 * The appointment, the invoice, the activation and the review are the record,
 * and all of them outlive this table. What is deleted here is the fact that
 * somebody was TOLD — useful for months, not for years, and the only reason
 * this table would otherwise grow without bound (docs/23-NOTIFICATIONS.md §15).
 *
 * ## One row this must never take
 *
 * An UNREAD `important` notification. In Phase 12 that is the one-star review
 * nobody has looked at yet, and a cleanup that quietly removed it would be the
 * one case where "tidying up" loses something a center is answerable for. It
 * is kept regardless of age.
 *
 * ## Bounded, and safe to run again
 *
 * Deletes in one bounded pass; recipients go with their notification through
 * the foreign key. Running twice deletes nothing the first pass already took.
 */
final class NotificationCleanup
{
    /**
     * Returns how many notifications it removed.
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        $at = ($now ?? CarbonImmutable::now())->utc();
        $cutoff = $at->subDays($this->retentionDays());

        $stale = Notification::query()
            ->select('id')
            ->where('created_at', '<', $cutoff)
            // Never an important one somebody has not read.
            ->whereNotIn('id', NotificationRecipient::query()
                ->select('notification_id')
                ->whereNull('read_at')
                ->whereIn('notification_id', Notification::query()
                    ->select('id')
                    ->where('severity', NotificationSeverity::Important->value)))
            ->orderBy('id')
            ->limit($this->maxPerRun())
            ->pluck('id');

        if ($stale->isEmpty()) {
            return 0;
        }

        // Recipients cascade with their notification.
        return Notification::query()->whereIn('id', $stale)->delete();
    }

    private function retentionDays(): int
    {
        $days = (int) config('notifications.retention.days', 180);

        return max(7, min($days, 3_650));
    }

    private function maxPerRun(): int
    {
        $max = (int) config('notifications.retention.max_per_run', 1000);

        return max(1, min($max, 20_000));
    }
}
