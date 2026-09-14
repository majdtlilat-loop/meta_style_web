<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements;

use App\Kernel\SaaS\Enums\SubscriptionStatus;

/**
 * What a tenant may do right now, derived from subscription status.
 *
 * Deliberately separate from entitlements. Entitlements say what was bought;
 * this says whether it may be used today. Conflating them breaks
 * reinstatement — a suspended tenant would appear to have lost the features
 * they are still paying for (docs/05-ENTITLEMENTS.md §5.1).
 */
enum TenantAccessLevel: string
{
    case Full = 'full';
    case ReadOnly = 'read_only';
    case Blocked = 'blocked';

    public static function fromSubscription(SubscriptionStatus $status): self
    {
        return match ($status) {
            // Past due keeps working during the grace period — cutting a center
            // off mid-haircut over a failed card is worse than the arrears.
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue => self::Full,

            SubscriptionStatus::Suspended => self::ReadOnly,

            SubscriptionStatus::Cancelled,
            SubscriptionStatus::Expired => self::Blocked,
        };
    }

    public function allowsUse(): bool
    {
        return $this === self::Full;
    }

    public function allowsWrites(): bool
    {
        return $this === self::Full;
    }
}
