<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements\Exceptions;

use RuntimeException;

/**
 * The catalog in config/entitlements.php is not usable.
 *
 * Thrown at resolution time rather than swallowed. A dependency cycle or a
 * reference to a key that does not exist is a configuration bug, and resolving
 * it "as best we can" would mean tenants silently owning — or silently losing —
 * capabilities.
 */
final class InvalidEntitlementCatalog extends RuntimeException
{
    /**
     * @param  list<string>  $cycle
     */
    public static function cycle(array $cycle): self
    {
        return new self(
            'Entitlement dependency cycle detected: '.implode(' → ', $cycle).
            '. Dependencies must form a directed acyclic graph.'
        );
    }

    public static function unknownDependency(string $entitlement, string $dependency): self
    {
        return new self(
            "Entitlement [{$entitlement}] requires [{$dependency}], which is not in the catalog."
        );
    }

    public static function unknownEntitlement(string $key): self
    {
        return new self(
            "Unknown entitlement [{$key}]. Entitlement keys are defined in config/entitlements.php."
        );
    }
}
