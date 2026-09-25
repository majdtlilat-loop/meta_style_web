<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Listeners;

use App\Kernel\Database\AfterCommit;
use App\Modules\Booking\Domain\Events\AppointmentConfirmed;
use App\Modules\Conversations\Application\GuestBookingConfirmations;

/**
 * Booking said a booking was confirmed; the channel tells a guest customer
 * (docs/25-WHATSAPP.md §22).
 *
 * Booking dispatches `AppointmentConfirmed` INSIDE its transition transaction
 * and knows nothing about WhatsApp. This only schedules the work for AFTER that
 * commit: a confirmation that rolls back sends nothing, and a provider that
 * refuses, times out or throws can never undo the booking. `AfterCommit`
 * reports a failure and drops it; {@see GuestConfirmationReconciler} replays
 * what a dropped callback left undone, and the notice row keeps both passes to
 * one decision.
 *
 * Registered here rather than in Notifications, which is in-app only and may
 * never send on a channel (docs/23 §2).
 */
final class ConfirmGuestBookingOnWhatsApp
{
    public function __construct(
        private readonly GuestBookingConfirmations $confirmations,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function handleConfirmed(AppointmentConfirmed $event): void
    {
        $this->afterCommit->run('whatsapp.booking_confirmation', function () use ($event): void {
            $this->confirmations->confirm($event->appointmentId);
        });
    }
}
