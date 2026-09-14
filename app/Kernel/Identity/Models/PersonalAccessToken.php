<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum tokens, resolved on the TENANT connection.
 *
 * This is the primary tenant binding and it is structural rather than a check
 * someone has to remember: a token row issued by Tenant A lives in Tenant A's
 * database, so looking it up while Tenant B is initialised finds nothing.
 * There is no shared token table whose scoping could be got wrong.
 *
 * The connection comes from the guard, so an attempt to authenticate with no
 * tenant initialised raises TenantConnectionNotInitialized rather than quietly
 * querying the control plane (docs/DECISIONS.md ADR-027).
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use UsesTenantConnection;
}
