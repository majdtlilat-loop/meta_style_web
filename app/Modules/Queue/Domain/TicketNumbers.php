<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The next human-readable number: `A001`, `A002`, `L015`.
 *
 * ## Why a table and a row lock
 *
 * Two receptionists press "new walk-in" at the same instant. Every obvious
 * implementation hands them the same number (docs/17-QUEUE.md §6, §35):
 *
 *   - `SELECT MAX(number) + 1` — both read the same maximum;
 *   - a counter in PHP — two workers, two counters, and nothing can detect it;
 *   - Redis alone — correct until Redis is restarted or the key expires, and
 *     the queue is not allowed to be wrong when the cache is empty.
 *
 * So the sequence is a ROW. Insert-or-ignore it, lock it `FOR UPDATE`,
 * increment, write it back. The second request waits for the first, which is
 * the entire point, and the wait is measured in the time one `UPDATE` takes.
 *
 * `unique(branch_id, business_date, prefix)` on `queue_tickets` is the backstop
 * behind that: even if somebody later writes a path that forgets the lock, the
 * database refuses the duplicate rather than printing it on paper.
 *
 * ## Not the branch lock
 *
 * `BranchLock` serialises everything that changes what is BOOKABLE. Issuing a
 * ticket changes nothing about availability, and queueing every walk-in behind
 * every booking would be a needless wait. A narrower row, locked the same way —
 * not a new locking mechanism (ADR-047).
 *
 * ## The day resets at the CENTER'S midnight
 *
 * `business_date` is the branch-local date, from `BranchClock`. A server clock
 * deciding when the day rolls over restarts the numbering mid-evening in
 * Baghdad, while customers are still holding yesterday's tickets (CLAUDE.md).
 */
final class TicketNumbers
{
    /**
     * @throws RuntimeException when called outside a transaction
     */
    public function next(int $branchId, string $businessDate, string $prefix): int
    {
        $connection = DB::connection('tenant');

        if ($connection->transactionLevel() < 1) {
            /*
             * A row lock outside a transaction is released the instant the
             * statement finishes, so it protects nothing while looking exactly
             * like it does — the same failure `BranchLock` refuses loudly, for
             * the same reason (ADR-047).
             */
            throw new RuntimeException('TicketNumbers::next() must be called inside a transaction.');
        }

        $now = CarbonImmutable::now()->utc();

        $key = [
            'branch_id' => $branchId,
            'business_date' => $businessDate,
            'prefix' => $prefix,
        ];

        // Races harmlessly: the loser's insert is ignored and it goes on to
        // lock the row the winner created.
        $connection->table('queue_sequences')->insertOrIgnore(
            $key + ['last_number' => 0, 'created_at' => $now, 'updated_at' => $now]
        );

        $row = $connection->table('queue_sequences')
            ->where($key)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            // Only reachable if the row was deleted between the insert and the
            // read, which nothing does. Failing is right: a ticket with no
            // number is worse than a refused ticket.
            throw new RuntimeException('The queue sequence could not be read.');
        }

        /** @var object{id: int, last_number: int} $row */
        $next = ((int) $row->last_number) + 1;

        $connection->table('queue_sequences')
            ->where('id', $row->id)
            ->update(['last_number' => $next, 'updated_at' => $now]);

        return $next;
    }
}
