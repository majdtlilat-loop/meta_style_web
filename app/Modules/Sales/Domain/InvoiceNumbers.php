<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The next invoice number for a branch-year. The Queue's proven pattern.
 *
 *     INSERT IGNORE the sequence row  →  SELECT ... FOR UPDATE  →  +1  →  write
 *
 * ## Exactly what is guaranteed
 *
 * The increment runs inside the caller's transaction — the one that also writes
 * the invoice. If anything in that transaction fails, the increment rolls back
 * with it, so a failed finalization consumes NO number. Two tills finalizing at
 * once serialise on the locked row and receive consecutive numbers.
 *
 * Numbers are therefore unique and gapless per branch per year for as long as
 * invoices are never deleted — the model refuses — and nobody edits
 * `invoice_sequences` by hand. That is the whole claim. It is stronger than an
 * AUTO_INCREMENT, which burns values on rollback, and it is not a promise the
 * database could keep against a manual edit (ADR-055).
 *
 * Never `SELECT MAX(sequence_number) + 1`: without a lock, two tills read the
 * same maximum.
 */
final class InvoiceNumbers
{
    /**
     * @throws RuntimeException when called outside a transaction
     */
    public function next(int $branchId, int $sequenceYear): int
    {
        $connection = DB::connection('tenant');

        if ($connection->transactionLevel() < 1) {
            // A row lock outside a transaction is released when the statement
            // ends: it would protect nothing while looking like it did.
            throw new RuntimeException('InvoiceNumbers::next() must be called inside a transaction.');
        }

        $now = CarbonImmutable::now()->utc();
        $key = ['branch_id' => $branchId, 'sequence_year' => $sequenceYear];

        $connection->table('invoice_sequences')->insertOrIgnore(
            $key + ['last_number' => 0, 'created_at' => $now, 'updated_at' => $now]
        );

        $row = $connection->table('invoice_sequences')
            ->where($key)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException('The invoice sequence could not be read.');
        }

        /** @var object{id: int, last_number: int} $row */
        $next = ((int) $row->last_number) + 1;

        $connection->table('invoice_sequences')
            ->where('id', $row->id)
            ->update(['last_number' => $next, 'updated_at' => $now]);

        return $next;
    }
}
