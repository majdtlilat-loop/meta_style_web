<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Enums;

/**
 * Groups entries for querying and retention (docs/08-AUDIT-SECURITY.md §6).
 */
enum AuditCategory: string
{
    case Tenancy = 'tenancy';
    case Security = 'security';
    case Config = 'config';
    case System = 'system';
    case Finance = 'finance';
    case Booking = 'booking';
    case Customer = 'customer';

    /** Financial and security history is kept far longer than the rest. */
    public function isLongRetention(): bool
    {
        return $this === self::Finance || $this === self::Security;
    }
}
