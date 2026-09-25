<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Exceptions;

use RuntimeException;

/**
 * Something tried to meter a resource code that is not in the catalog.
 *
 * Thrown rather than ignored. A silently accepted typo would write rows nothing
 * can interpret, count usage nobody is charged for, and hide the fact that a
 * feature is not metered at all — a failure that surfaces as a revenue gap
 * months later rather than as an error today (docs/26-USAGE-QUOTAS.md §2).
 */
final class UnknownResource extends RuntimeException
{
    public static function code(string $resource): self
    {
        return new self(sprintf('"%s" is not a metered resource. Add it to config/usage.php.', $resource));
    }
}
