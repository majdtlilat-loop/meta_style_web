<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Enums;

/**
 * Active or cancelled. "Upcoming" (a renewal waiting for the current term to
 * end) and "expired" are derived from `starts_at` and `expires_at`, never stored
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21).
 */
enum CustomerMembershipStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
