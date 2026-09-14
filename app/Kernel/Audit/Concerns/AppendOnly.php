<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Concerns;

use RuntimeException;

/**
 * Blocks updates and deletes on audit models.
 *
 * This is the application-level layer of the append-only guarantee. It is the
 * weakest of the three and is not meant to stand alone: production also grants
 * the application database user INSERT and SELECT only on audit tables, which
 * is the control that actually holds. Retention pruning runs as a separate
 * maintenance user (docs/08-AUDIT-SECURITY.md §5).
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'Audit entries are append-only and cannot be updated. If the recorded '
                .'facts were wrong, write a new entry describing the correction.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'Audit entries are append-only and cannot be deleted through the '
                .'application. Retention pruning runs as a separate maintenance task.'
            );
        });
    }
}
