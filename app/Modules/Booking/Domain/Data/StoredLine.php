<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Modules\Booking\Domain\Availability\StoredLayout;
use App\Modules\Resources\Domain\Models\OperationalResource;

/**
 * One item of an EXISTING appointment, as a move sees it.
 *
 * The counterpart of {@see ResolvedLine} for a reschedule. A new booking is
 * resolved from the catalog; a booking being moved is not — its duration, its
 * place inside the visit and the rooms it holds were agreed when it was made
 * and a change of time does not renegotiate them (docs/15-BOOKING.md §3).
 *
 * Built only by {@see StoredLayout}, from the same three
 * facts `RescheduleAppointment` reads under the lock, so an advisory move slot
 * and the authoritative re-check ask the same question.
 */
final readonly class StoredLine
{
    /**
     * @param  list<int>  $candidates  employee ids who may take it, in the order
     *                                 the reschedule tries them; EMPTY means it
     *                                 moves unassigned
     * @param  list<array{resource: OperationalResource, quantity: int}>  $held
     */
    public function __construct(
        public int $offsetMinutes,
        public int $durationMinutes,
        public array $candidates,
        public array $held,
    ) {}
}
