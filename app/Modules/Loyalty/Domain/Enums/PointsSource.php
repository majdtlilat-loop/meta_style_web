<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

/**
 * What caused a points movement. With the source's uuid and the movement's
 * kind, it is unique: the same payment, refund, visit or redemption can never
 * move points twice (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §25).
 */
enum PointsSource: string
{
    /** A payment that succeeded — spend earning. */
    case Payment = 'payment';

    /** A refund that succeeded — reversal of spend earning. */
    case Refund = 'refund';

    /** A completed visit — visit earning. */
    case Journey = 'journey';

    /** A redemption at checkout, and its return. */
    case Benefit = 'benefit';

    /** A manager's adjustment. */
    case Manual = 'manual';

    /** The earning whose points settled an earlier shortfall — its own uuid. */
    case Recovery = 'recovery';

    /** Points aging out. */
    case Expiry = 'expiry';
}
