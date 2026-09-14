<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Concerns;

use LogicException;

/**
 * A published financial document: written once, never updated, never deleted.
 *
 * The application-level half of invoice immutability. The other halves are that
 * no Action exists that could edit an invoice, and an architecture scan that
 * refuses query-builder writes to the invoice tables — model events cannot see
 * `DB::table('invoices')->update()`, so that path is closed by the scan instead
 * (docs/18-SALES.md §16, ADR-054).
 *
 * A correction is a NEW fact — a void recorded on the sale today, a credit note
 * later — never a rewrite of what a customer was already handed.
 */
trait ImmutableDocument
{
    public static function bootImmutableDocument(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'A published invoice is immutable. Record a correction as a new fact '
                .'(a void on the sale) instead of editing the document.'
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'A published invoice is never deleted. Its number, totals and lines '
                .'are financial history.'
            );
        });
    }
}
