<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Modules\Booking\Domain\Availability\LineResolver;

/**
 * "This line needs N of resource type X."
 *
 * The catalog's `service_resource_requirements` row, read once by
 * {@see LineResolver} and carried on
 * the resolved line from then on — so nothing downstream re-reads the catalog
 * and nothing downstream can pick up a requirement that changed between the
 * availability query and the booking (docs/13-ROADMAP.md Phase 7 §43).
 *
 * Ids, not uuids: this never crosses the HTTP boundary. It is the internal
 * shape between the resolver and the allocator.
 */
final readonly class ResourceRequirement
{
    public function __construct(
        public int $resourceTypeId,
        public int $quantity,
    ) {}
}
