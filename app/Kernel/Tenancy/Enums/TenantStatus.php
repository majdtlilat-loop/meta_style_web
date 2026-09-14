<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Enums;

/**
 * Lifecycle state of a tenant (docs/02-TENANCY.md §8.1).
 */
enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Archived = 'archived';
    case Failed = 'failed';

    /** Has a usable operational database. */
    public function hasDatabase(): bool
    {
        return in_array($this, [self::Active, self::Suspended, self::Cancelled], true);
    }

    /** Should be included when migrating all tenants. */
    public function isMigratable(): bool
    {
        return in_array($this, [self::Active, self::Suspended, self::Cancelled], true);
    }
}
