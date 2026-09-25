<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Enums;

/**
 * What caused a package movement. With the source uuid and the kind it is
 * unique, so every movement happens once (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §25).
 */
enum PackageSource: string
{
    /** Activation — per package item. */
    case Activation = 'activation';

    /** A redemption at checkout, and its return. */
    case Benefit = 'benefit';

    /** Cancelling the package — per package item. */
    case Cancellation = 'cancellation';
}
