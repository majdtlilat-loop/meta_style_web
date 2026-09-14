<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Enums;

/**
 * Where a tenant stands commercially (docs/00-PRODUCT-OVERVIEW.md §6).
 *
 * This is NOT an entitlement. Entitlements say what a tenant bought; this says
 * whether they may use it right now. Keeping the two apart is what makes
 * reinstatement work: a suspended tenant on the Enterprise plan still owns
 * every capability, they simply cannot write.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** May the tenant use the product at all? */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /** Access continues but every write is refused. */
    public function isReadOnly(): bool
    {
        return $this === self::Suspended;
    }

    /** Login and data export only. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }

    public function isTrial(): bool
    {
        return $this === self::Trialing;
    }
}
