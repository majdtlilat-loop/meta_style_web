<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Exceptions;

use RuntimeException;

/**
 * A tenant's database does not exist when it was expected to.
 *
 * Provisioning creates databases; migration does not. Laravel's `migrate`
 * command will silently CREATE a missing MySQL/MariaDB database when run with
 * --force, which for Meta Style is the wrong behaviour: it would turn a stale
 * or mistyped `tenancy_db_name` into a real, empty, orphaned database that the
 * control plane knows nothing about, and report success.
 *
 * The migrator checks first and fails instead.
 */
final class TenantDatabaseMissing extends RuntimeException
{
    public static function for(string $database, string $tenantId): self
    {
        return new self(
            "Database [{$database}] for tenant [{$tenantId}] does not exist. Migrations do "
            .'not create databases — provision the tenant first '
            .'(metastyle:tenant:provision --retry='.$tenantId.').'
        );
    }
}
