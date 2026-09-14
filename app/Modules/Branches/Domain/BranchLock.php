<?php

declare(strict_types=1);

namespace App\Modules\Branches\Domain;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE serialisation point for anything that can change what is bookable.
 *
 * A pessimistic row lock on `branches`, taken inside a transaction. Phase 6
 * used it in one place — the booking write path — as an inline
 * `lockForUpdate()`. Phase 7 gives it a name because it now has several
 * callers, and the whole guarantee depends on all of them taking the SAME lock
 * (docs/DECISIONS.md ADR-044, ADR-047).
 *
 * ## The race this closes
 *
 *   Request A: books the last slot on Laser Machine 2
 *   Request B: reduces Laser Machine 2's capacity to zero
 *
 * Both read a consistent world, both decide they are fine, both commit. The
 * booking now holds capacity that no longer exists. Every mutation that changes
 * bookability has the same shape:
 *
 *   - resource capacity, activation, archival, branch move
 *   - a service's resource requirements
 *   - an employee availability block
 *
 * None of them is frequent, and all of them are cheap. Taking the branch lock
 * makes each one wait behind any booking in flight and vice versa, which turns
 * an interleaving nobody can reason about into a queue of two
 * (Phase 7 corrections §2).
 *
 * ## Why the branch row rather than the thing being changed
 *
 * Because booking cannot lock the thing being changed: at the moment it takes
 * the lock it has not yet chosen an employee or a resource. The branch is the
 * one row both sides can name in advance, and every resource, employee block
 * and appointment belongs to exactly one branch — which is why
 * `resources.branch_id` and `employee_availability_blocks.branch_id` are both
 * NOT NULL.
 *
 * ## Ordering
 *
 * Locks are always taken in ascending branch id. Two callers that each need
 * branches 3 and 7 — moving a resource between them, say — would deadlock if
 * one took 7 first. Ascending order is arbitrary and it is consistent, which is
 * the only property that matters.
 *
 * ## Cost, stated plainly
 *
 * Writes at one branch are serialised. At a booking rate measured in bookings
 * per hour and a settings-change rate measured in times per month, that is
 * milliseconds of contention for a guarantee that would otherwise need a
 * distributed lock service.
 */
final class BranchLock
{
    /**
     * Locks these branch rows for the rest of the current transaction.
     *
     * @param  list<int>  $branchIds
     *
     * @throws RuntimeException when called outside a transaction
     */
    public function acquire(array $branchIds): void
    {
        $ids = array_values(array_unique(array_filter($branchIds, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        $connection = DB::connection('tenant');

        if ($connection->transactionLevel() < 1) {
            /*
             * A row lock outside a transaction is released the instant the
             * statement finishes, so it protects nothing while looking exactly
             * like it does. Failing loudly is the only way that mistake is ever
             * noticed.
             */
            throw new RuntimeException('BranchLock::acquire() must be called inside a transaction.');
        }

        sort($ids);

        $connection->table('branches')
            ->whereIn('id', $ids)
            // Ordered in SQL as well: the lock order is what prevents the
            // deadlock, and it is the database that decides the row order.
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The common single-branch case.
     */
    public function acquireOne(int $branchId): void
    {
        $this->acquire([$branchId]);
    }

    /**
     * Every active branch.
     *
     * For a change with center-wide reach — a service's resource requirements
     * apply everywhere the service is offered, and working out exactly which
     * branches those are costs more than locking the handful that exist.
     */
    public function acquireAll(): void
    {
        /** @var list<int> $ids */
        $ids = DB::connection('tenant')
            ->table('branches')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->acquire($ids);
    }
}
