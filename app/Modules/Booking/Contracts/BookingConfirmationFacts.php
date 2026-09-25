<?php

declare(strict_types=1);

namespace App\Modules\Booking\Contracts;

use App\Modules\Booking\Data\BookingConfirmationData;
use Carbon\CarbonImmutable;

/**
 * What a channel may know about a booking in order to confirm it to the
 * customer (docs/25-WHATSAPP.md §22).
 *
 * READ-ONLY. A channel reads bookings through this and the
 * `BookingConfirmationData` it returns, never through the Booking models — so
 * it can tell a customer about a booking without ever being able to change one
 * (docs/04-MODULE-BOUNDARIES.md §2.1).
 */
interface BookingConfirmationFacts
{
    /**
     * One booking, by the id `AppointmentConfirmed` carries; null when there is
     * no such booking or its customer record is gone.
     */
    public function find(int $appointmentId): ?BookingConfirmationData;

    /**
     * The same, by the booking's uuid.
     */
    public function findByUuid(string $uuid): ?BookingConfirmationData;

    /**
     * CONFIRMED bookings of GUEST customers (no customer account, ADR-041)
     * confirmed at or after `$confirmedFrom` and starting after `$startsAfter`
     * — the candidates a confirmation reconciler looks at, a page at a time.
     *
     * @return array<int, string> appointment id => uuid, ids greater than
     *                            `$afterId` in ascending order, at most `$limit`
     */
    public function confirmedGuestBookings(
        CarbonImmutable $confirmedFrom,
        CarbonImmutable $startsAfter,
        int $afterId,
        int $limit,
    ): array;
}
