<?php

declare(strict_types=1);

namespace App\Kernel\Database\Concerns;

use LogicException;

/**
 * A history row that is written once and never changed.
 *
 * For the records a balance is PROVEN from — loyalty points, package sessions,
 * membership uses: a mistake is corrected by a new row (a reversal, an
 * adjustment), never by editing or deleting an old one. Refuses update and
 * delete at the model; the query-builder path, which model events cannot see,
 * is refused by an architecture scan instead
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§3, 14).
 *
 * Generic on purpose: it knows nothing about any module.
 */
trait AppendOnlyHistory
{
    public static function bootAppendOnlyHistory(): void
    {
        static::updating(function (): never {
            throw new LogicException('This history is append-only. Record a correcting entry instead of editing this one.');
        });

        static::deleting(function (): never {
            throw new LogicException('History entries are never deleted.');
        });
    }
}
