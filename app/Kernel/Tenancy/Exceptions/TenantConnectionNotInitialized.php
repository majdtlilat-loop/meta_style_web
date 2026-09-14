<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant data is accessed without an initialised tenant context.
 *
 * This is the fail-closed guarantee from docs/02-TENANCY.md §4. The alternative
 * — silently falling back to another tenant's database or to the control
 * database — is the single worst failure this architecture can have, so it is
 * turned into a loud, named exception instead.
 */
final class TenantConnectionNotInitialized extends RuntimeException
{
    public static function forQuery(?string $context = null): self
    {
        $suffix = $context !== null ? " while accessing [{$context}]" : '';

        return new self(
            'No tenant is initialised, so the "tenant" database connection has no database'
            .$suffix.'. Tenant data must never be read without an explicit tenant context. '
            .'Initialise a tenant first (see docs/02-TENANCY.md §4).'
        );
    }
}
