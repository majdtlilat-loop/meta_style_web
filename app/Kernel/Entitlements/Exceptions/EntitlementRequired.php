<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements\Exceptions;

use RuntimeException;

/**
 * The tenant does not own the capability being used.
 *
 * Carries the key so the client can show a targeted upgrade prompt rather than
 * a generic refusal (docs/05-ENTITLEMENTS.md §6.1).
 */
final class EntitlementRequired extends RuntimeException
{
    public function __construct(public readonly string $entitlement)
    {
        parent::__construct(__("This center's plan does not include [:entitlement].", ['entitlement' => $entitlement]));
    }
}
