<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

/**
 * Which flow produced a sale. STORED, never inferred from nullable relations:
 * "how much did walk-ins spend" must not depend on a visit row still existing
 * (docs/18-SALES.md §30).
 */
enum SaleSource: string
{
    /** Opened at the till with no visit — a product bought over the counter. */
    case Pos = 'pos';

    /** Checkout of a booked visit. */
    case JourneyCheckout = 'journey_checkout';

    /** Checkout of a walk-in visit. */
    case WalkInCheckout = 'walk_in_checkout';
}
