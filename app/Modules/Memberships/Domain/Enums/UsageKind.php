<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Enums;

/**
 * A use of a membership benefit, or its return
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §12).
 */
enum UsageKind: string
{
    case Use = 'use';
    case Reversal = 'reversal';

    /** +1 when it counts as used, −1 when it gives a use back. */
    public function sign(): int
    {
        return $this === self::Use ? 1 : -1;
    }
}
