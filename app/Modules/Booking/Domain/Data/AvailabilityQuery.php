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
     */
    public function __construct(
        public string $branchUuid,
        public array $lines,
        public string $fromDate,
        public string $toDate,
    ) {}

    /**
     * @param  list<BookingLine>  $lines
     */
    public static function forDay(string $branchUuid, array $lines, string $date): self
    {
        return new self($branchUuid, $lines, $date, $date);
    }
}
