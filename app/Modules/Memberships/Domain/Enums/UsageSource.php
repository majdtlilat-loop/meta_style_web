<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Enums;

/**
 * What a benefit usage row answers to. Today one thing: a benefit applied to a
 * sale line at checkout, keyed on that application's own uuid — its use and
 * its reversal share it, which makes both idempotent
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §12).
 */
enum UsageSource: string
{
    case Benefit = 'benefit';
}
