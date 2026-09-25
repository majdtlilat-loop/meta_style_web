<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Enums;

/**
 * A customer's package is active or cancelled. "Expired" is not stored: it is
 * `expires_at` having passed, derived whenever it is asked, so nothing needs a
 * scheduler to make it true (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §18).
 */
enum CustomerPackageStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
