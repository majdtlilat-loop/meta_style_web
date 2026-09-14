<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Concerns;

use App\Kernel\Tenancy\TenantConnectionGuard;

/**
 * Marks an Eloquent model as living in the tenant database.
 *
 * Resolving the connection through the guard rather than declaring
 * `$connection = 'tenant'` means a query with no tenant initialised raises
 * TenantConnectionNotInitialized — naming the model — instead of a driver
 * error surfacing three layers away. This is the fail-closed rule applied at
 * the model layer (docs/02-TENANCY.md §4).
 *
 * Every operational tenant model uses this. An architecture test enforces it.
 */
trait UsesTenantConnection
{
    public function getConnectionName(): string
    {
        app(TenantConnectionGuard::class)->ensureInitialized(static::class);

        return TenantConnectionGuard::CONNECTION;
    }
}
