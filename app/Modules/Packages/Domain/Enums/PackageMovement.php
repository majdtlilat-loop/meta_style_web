<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Enums;

/**
 * What happened to a package's sessions (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §14).
 *
 *   allocation    activation gave them                  +
 *   redemption    a performed service used them          −
 *   reversal      a redemption was given back            +
 *   cancellation  what was left was forfeited            −
 */
enum PackageMovement: string
{
    case Allocation = 'allocation';
    case Redemption = 'redemption';
    case Reversal = 'reversal';
    case Cancellation = 'cancellation';

    /** +1 when it adds sessions, −1 when it takes them. */
    public function sign(): int
    {
        return match ($this) {
            self::Allocation, self::Reversal => 1,
            self::Redemption, self::Cancellation => -1,
        };
    }
}
