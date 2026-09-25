<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Events;

/**
 * A booking was confirmed — the center expects the customer.
 *
 * Dispatched synchronously INSIDE the transition transaction, carrying
 * identifiers only. Booking has never known what happens next and still does
 * not: a listener that wants to tell the customer schedules that work for after
 * the commit, so a failed notification can never undo a booking change
 * (docs/23-NOTIFICATIONS.md §11).
 */
final readonly class AppointmentConfirmed
{
    public function __construct(
        public int $appointmentId,
        public int $branchId,
        public int $customerId,
    ) {}
}
