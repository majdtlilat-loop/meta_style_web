<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Listeners;

use App\Kernel\Database\AfterCommit;
use App\Modules\Booking\Domain\Events\AppointmentCancelled;
use App\Modules\Booking\Domain\Events\AppointmentConfirmed;
use App\Modules\Booking\Domain\Events\AppointmentRescheduled;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Notifications\Application\BranchTimes;
use App\Modules\Notifications\Application\CustomerInboxes;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Models\Notification;

/**
 * Telling a customer what happened to their booking.
 *
 * Three of the five transitions, and deliberately not all five:
 *
 *   confirmed      the center acted; the customer did not necessarily know
 *   rescheduled    the time they were told is no longer the time
 *   cancelled      the appointment they were expecting is gone
 *
 * `booked` is not here — the person who made the booking was present when it
 * was made — and neither is `completed` or `no_show`: the customer was either
 * there or was not, and being told which by an app is at best redundant
 * (docs/23-NOTIFICATIONS.md §3).
 *
 * ## The booking commits first, always
 *
 * Booking dispatches inside its own transaction; everything here is scheduled
 * for AFTER that commits, and a failure is reported and dropped. A cancellation
 * that could not be announced is still a cancellation, and a reschedule that
 * rolled back because an inbox row failed would be an outright bug (§11).
 *
 * ## A reschedule un-sends the old reminder
 *
 * The reminder for the OLD time is deleted, not corrected: the sweep will write
 * a fresh one for the new time, keyed on the same appointment, and a customer
 * must never be left holding a notice for an hour nobody is expecting them
 * (§13). Deleting is allowed here because a notification is communication
 * state, not domain truth — the appointment itself is untouched.
 */
final class NotifyOnBooking
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly CustomerInboxes $inboxes,
        private readonly BranchTimes $times,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function handleConfirmed(AppointmentConfirmed $event): void
    {
        $this->announce($event->appointmentId, $event->customerId, NotificationType::AppointmentConfirmed);
    }

    public function handleRescheduled(AppointmentRescheduled $event): void
    {
        $this->announce($event->appointmentId, $event->customerId, NotificationType::AppointmentRescheduled, true);
    }

    public function handleCancelled(AppointmentCancelled $event): void
    {
        $this->announce($event->appointmentId, $event->customerId, NotificationType::AppointmentCancelled, true);
    }

    private function announce(int $appointmentId, int $customerId, NotificationType $type, bool $dropReminder = false): void
    {
        $this->afterCommit->run('notifications.'.$type->value, function () use ($appointmentId, $customerId, $type, $dropReminder): void {
            /** @var Appointment|null $appointment */
            $appointment = Appointment::query()->whereKey($appointmentId)->first();

            if (! $appointment instanceof Appointment) {
                return;
            }

            if ($dropReminder) {
                $this->forgetReminder($appointment);
            }

            $recipients = $this->inboxes->forCustomer($customerId);

            if ($recipients === []) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: $type,
                sourceType: 'appointment',
                sourceUuid: $appointment->uuid,
                params: ['at' => $this->localTime($appointment)],
                recipients: $recipients,
                branchId: (int) $appointment->branch_id,
            ));
        });
    }

    /**
     * The appointment's time as the CUSTOMER would read it: the branch's own
     * clock, carried with its offset so nothing downstream has to guess
     * (docs/10-API-FOUNDATION.md §9).
     */
    private function localTime(Appointment $appointment): string
    {
        return $this->times->iso($appointment->starts_at, (int) $appointment->branch_id);
    }

    /**
     * Removes a reminder that is now about the wrong time — or about an
     * appointment that is not happening.
     */
    private function forgetReminder(Appointment $appointment): void
    {
        Notification::query()
            ->where('type', NotificationType::AppointmentReminder->value)
            ->where('source_type', 'appointment')
            ->where('source_uuid', $appointment->uuid)
            ->delete();
    }
}
