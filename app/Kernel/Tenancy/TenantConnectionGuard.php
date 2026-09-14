<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Guards the runtime "tenant" database connection.
 *
 * While no tenant is initialised the connection does not exist in config at
 * all, so any query against it already fails. This guard exists to make that
 * failure legible — a named exception naming the missing context, rather than
 * a raw "Database connection [tenant] not configured" surfacing three layers
 * away from the cause — and to give callers a cheap `isInitialized()` check
 * that does not involve catching exceptions.
 *
 * The guarantee it protects is the one in docs/02-TENANCY.md §4: tenant data
 * access without a tenant must FAIL, never fall back to the control database
 * and never inherit the previous tenant.
 */
final class TenantConnectionGuard
{
    public const CONNECTION = 'tenant';

    public function __construct(private readonly Config $config) {}

    /**
     * The database currently bound to the tenant connection, or null.
     */
    public function boundDatabase(): ?string
    {
        $database = $this->config->get('database.connections.'.self::CONNECTION.'.database');

        return is_string($database) && $database !== '' ? $database : null;
    }

    public function isInitialized(): bool
    {
        return $this->boundDatabase() !== null;
    }

    /**
     * @throws TenantConnectionNotInitialized
     */
    public function ensureInitialized(?string $context = null): void
    {
        if (! $this->isInitialized()) {
            throw TenantConnectionNotInitialized::forQuery($context);
        }
    }

    /**
     * Asserts that the bound database is one Meta Style generated.
     *
     * Cheap defence in depth for anything about to interpolate the name into
     * SQL, where no parameter binding is possible (see TenantDatabaseName).
     */
    public function assertBoundDatabaseIsValid(): string
    {
        $this->ensureInitialized();

        return TenantDatabaseName::assertValid((string) $this->boundDatabase());
    }
}
