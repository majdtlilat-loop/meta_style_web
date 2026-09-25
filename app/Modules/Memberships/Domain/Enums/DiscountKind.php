<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Enums;

/**
 * How a membership benefit discounts a service line.
 *
 *   percent  `basis_points` of the line (1250 is 12.5%; 10000 makes it free)
 *   fixed    `amount_minor` per unit, never more than the line
 */
enum DiscountKind: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
