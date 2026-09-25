<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Concerns;

use LogicException;

/**
 * A financial fact that is written once.
 *
 * Refuses update and delete at the model. That does not cover
 * `DB::table('finance_entries')->update()`, which model events cannot see — an
 * architecture scan refuses that path instead (docs/20-FINANCE.md §36).
 *
 * A wrong entry is corrected by a new one: a reversal, never an edit.
 */
trait AppendOnlyRecord
{
    public static function bootAppendOnlyRecord(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ledger entries are append-only. Record a correcting entry instead of editing this one.');
        });

        static::deleting(function (): never {
            throw new LogicException('Ledger entries are never deleted.');
        });
    }
}
