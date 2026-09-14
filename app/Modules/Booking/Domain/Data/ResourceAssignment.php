<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Modules\Booking\Domain\Availability\ResourceAllocator;
use App\Modules\Resources\Domain\Models\OperationalResource;

/**
 * A concrete resource the engine has decided to hold, and how much of it.
 *
 * The output of {@see ResourceAllocator}
 * and the input to the reservation row. Carries the resource itself so the
 * snapshots can be written without a second lookup — the same reason
 * {@see ResolvedLine} carries its Service.
 *
 * `quantity` is greater than 1 when one resource satisfies several units of a
 * requirement: a service needing two places in a hammam of capacity four is ONE
 * assignment of quantity 2, not two assignments (Phase 7 §4).
 */
final readonly class ResourceAssignment
{
    public function __construct(
        public OperationalResource $resource,
        public int $quantity,
    ) {}
}
