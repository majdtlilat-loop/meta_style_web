<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Enums;

/**
 * Where a tenant is in the provisioning pipeline (docs/02-TENANCY.md §8.2).
 *
 * A tenant is only ever reported ready when this is Completed. A partially
 * provisioned tenant must never look active.
 */
enum ProvisioningStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Pending;
    }
}
