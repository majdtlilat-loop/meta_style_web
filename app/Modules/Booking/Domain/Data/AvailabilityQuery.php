<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

/**
 * "When could this be booked?"
 *
 * Dates are branch-local `Y-m-d` strings, not timestamps, because that is the
 * question actually being asked: a customer picking "Thursday" means Thursday
 * where the branch is, and turning it into an instant before the branch is
 * known is how a booking lands on the wrong day
 * (docs/10-API-FOUNDATION.md §8).
 *
 * The service list is a list because availability for a multi-service visit is
 * NOT the intersection of each service's availability — the visit runs
 * sequentially and has to fit end to end inside one opening interval. Asking
 * per service and intersecting would offer slots that cannot hold the whole
 * booking (docs/13-ROADMAP.md Phase 6 §4).
 */
final readonly class AvailabilityQuery
{
    /**
     * @param  list<BookingLine>  $lines
     * @param  string|null  $movingAppointmentUuid  set only by {@see forMove()}
     */
    public function __construct(
        public string $branchUuid,
        public array $lines,
        public string $fromDate,
        public string $toDate,
        public ?string $movingAppointmentUuid = null,
    ) {}

    /**
     * @param  list<BookingLine>  $lines
     */
    public static function forDay(string $branchUuid, array $lines, string $date): self
    {
        return new self($branchUuid, $lines, $date, $date);
    }

    /**
     * "Where could this EXISTING appointment move to?"
     *
     * No lines: a move is not a new booking and must not be re-resolved from
     * the catalog. The engine lays the visit out from its STORED items — their
     * durations, their offsets from the visit's start, the named-employee
     * requirement and the rooms already held — which is exactly what
     * `RescheduleAppointment` re-checks under the lock. And it ignores the
     * appointment's own current time, so moving a sixty-minute booking by
     * thirty minutes is not refused by the copy of itself it is moving away
     * from (docs/15-BOOKING.md §4).
     *
     * Staff only: the public channel refuses it. Still ADVISORY, like every
     * slot — the reschedule decides.
     */
    public static function forMove(string $branchUuid, string $appointmentUuid, string $fromDate, ?string $toDate = null): self
    {
        return new self($branchUuid, [], $fromDate, $toDate ?? $fromDate, $appointmentUuid);
    }

    public function isMove(): bool
    {
        return $this->movingAppointmentUuid !== null;
    }
}
