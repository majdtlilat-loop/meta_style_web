<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Exceptions;

use RuntimeException;
use Throwable;

final class TenantProvisioningFailed extends RuntimeException
{
    public static function at(string $step, string $tenantId, Throwable $previous): self
    {
        return new self(
            "Provisioning tenant [{$tenantId}] failed at step [{$step}]: ".$previous->getMessage(),
            0,
            $previous,
        );
    }
}
