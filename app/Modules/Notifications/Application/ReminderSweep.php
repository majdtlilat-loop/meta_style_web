<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Models\Notification;
use Carbon\CarbonImmutable;

/**
 * "Your appointment is tomorrow."
 *
 * ## A sweep, not a job per appointment
 *
 * Scheduling one delayed job per booking would mean a job for every
 * appointment every center ever takes, each one holding a time that a
 * reschedule silently invalidates — and no way to find the stale ones. This
 * runs on a clock instead, looks at a BOUNDED window of appointments, and
 * writes what is missing. A reschedule needs no cleanup beyond dropping the
 * reminder that was already written, which `NotifyOnBooking` does
 * (docs/23-NOTIFICATIONS.md §13).
 *
 * ## Repeated runs write nothing
 *
 * The notification is keyed on the APPOINTMENT, so the second pass over the
 * same window collides on `notifications(type, source_type, source_uuid)` and
 * inserts nothing. Overlapping windows are therefore free, which is what makes
 * the schedule safe to run often and safe to catch up after an outage (§12).
 *
 * ## Only appointments that are still going to happen
 *
 * `booked` and `confirmed` — the two statuses that occupy the calendar.
 * Cancelled, completed and no-show are all in the past tense, and reminding
 * somebody about an appointment that was cancelled yesterday is worse than
 * saying nothing.
 */
final class ReminderSweep
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly CustomerInboxes $inboxes,
        private readonly BranchTimes $times,
    ) {}

    /**
     * Writes the reminders due in the lead window. Returns how many it wrote.
     */
    public function sweep(?CarbonImmutable $now = null): int
    {
        if (! (bool) config('notifications.reminders.enabled', true)) {
            return 0;
        }

        $at = ($now ?? CarbonImmutable::now())->utc();
        $until = $at->addMinutes($this->leadMinutes());

        /** @var list<Appointment> $due */
        $due = Appointment::query()
            ->whereIn('status', [AppointmentStatus::Booked->value, AppointmentStatus::Confirmed->value])
            // The bound: an indexed range on (status, starts_at), never a scan
            // of every appointment a center has ever taken (§18).
            ->where('starts_at', '>', $at)
            ->where('starts_at', '<=', $until)
            ->whereNotIn('uuid', Notification::query()
                ->select('source_uuid')
                ->where('type', NotificationType::AppointmentReminder->value)
                ->where('source_type', 'appointment'))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($this->maxPerRun())
            ->get()
            ->all();

        $written = 0;

        foreach ($due as $appointment) {
            $recipients = $this->inboxes->forCustomer((int) $appointment->customer_id);

            if ($recipients === []) {
                // A guest with no login. There is no inbox to write to, and
                // Phase 12 builds no provider to reach them another way (§4).
                continue;
            }

            $written += $this->center->deliver(new NotificationRequest(
                type: NotificationType::AppointmentReminder,
                sourceType: 'appointment',
                sourceUuid: $appointment->uuid,
                // The branch's own clock: "14:30" means 14:30 where the
                // customer is going (docs/10-API-FOUNDATION.md §8).
                params: ['at' => $this->times->iso($appointment->starts_at, (int) $appointment->branch_id)],
                recipients: $recipients,
                branchId: (int) $appointment->branch_id,
            ), $at) > 0 ? 1 : 0;
        }

        return $written;
    }

    /**
     * How far ahead to look. One number, read here, bounded so a mistyped
     * setting cannot turn the sweep into a scan of next year (§13).
     */
    private function leadMinutes(): int
    {
        $lead = (int) config('notifications.reminders.lead_minutes', 1440);

        return max(5, min($lead, 10_080));
    }

    private function maxPerRun(): int
    {
        $max = (int) config('notifications.reminders.max_per_run', 500);

        return max(1, min($max, 5_000));
    }
}
