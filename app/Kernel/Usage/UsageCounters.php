<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\Usage\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The period counter: creating it safely, and spending against it atomically.
 *
 * ## Creating the first row of a period
 *
 * Two requests can arrive in the same millisecond on the first of the month.
 * Both find no row, both try to create one, and a check-then-insert gives one
 * of them a duplicate-key error — surfaced to a customer as a 500 on the first
 * message of the month (docs/26-USAGE-QUOTAS.md §5).
 *
 *     INSERT IGNORE  →  SELECT
 *
 * `insertOrIgnore` cannot fail on a duplicate, and the SELECT afterwards reads
 * whichever row won. Both callers end up with the same canonical row; neither
 * sees an error. The `unique(resource, period_start)` index is what makes it
 * true, so this is a database guarantee rather than a timing hope.
 *
 * ## Spending
 *
 * ONE conditional UPDATE, and the condition is part of it:
 *
 *     UPDATE usage_counters
 *        SET used = used + ?
 *      WHERE resource = ? AND period_start = ?
 *        AND (allowance_snapshot IS NULL OR used + ? <= allowance_snapshot)
 *
 * The number of affected rows IS the answer. There is no read, no comparison in
 * PHP, and therefore no window between deciding and acting. At 99 of 100 with
 * two simultaneous requests for the last unit, InnoDB serialises the two
 * statements on the row: the first matches and writes 100, the second finds
 * `100 + 1 > 100`, matches nothing, and is refused. 101 is unreachable (§5).
 *
 * A `SELECT ... FOR UPDATE` followed by an UPDATE would also be correct, and is
 * two round trips and a held lock to answer a question one statement answers.
 */
final class UsageCounters
{
    public function __construct(private readonly Allowances $allowances) {}

    /**
     * The canonical counter row for a period, creating it if this is the first
     * use.
     *
     * The allowance is resolved from the control plane ONCE, here, and
     * snapshotted onto the row. Every later check reads the snapshot, so the
     * hot path never crosses databases and a plan change cannot retroactively
     * invalidate usage that was already allowed (§7).
     */
    public function forPeriod(string $tenantId, string $resource, UsagePeriod $period): UsageCounter
    {
        $existing = $this->find($resource, $period);

        if ($existing instanceof UsageCounter) {
            return $existing;
        }

        $allowance = $this->allowances->resolve($tenantId, $resource);
        $now = CarbonImmutable::now()->utc();

        DB::connection('tenant')->table('usage_counters')->insertOrIgnore([
            'resource' => $resource,
            'period_start' => $period->start,
            'period_end' => $period->end,
            'allowance_snapshot' => $allowance->allowance,
            'allowance_version' => $allowance->version,
            'used' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Re-read rather than trusting the insert. If a competing request won,
        // ITS row is the canonical one and this one wrote nothing.
        $counter = $this->find($resource, $period);

        if (! $counter instanceof UsageCounter) {
            // Unreachable barring the row being deleted between the two
            // statements. Loud rather than silently returning a fresh object
            // nothing is counting into.
            throw new RuntimeException(sprintf('The usage counter for "%s" could not be read.', $resource));
        }

        return $counter;
    }

    /**
     * Spends `$quantity`, or refuses — in one statement.
     *
     * @return bool true when it was spent; false when the allowance would have
     *              been exceeded, or the row has since gone
     */
    public function consume(UsageCounter $counter, int $quantity): bool
    {
        if ($quantity < 1) {
            // Nothing to spend. Not an error — a caller metering "zero tokens
            // returned" is reporting a fact, and it must not count as a refusal.
            return true;
        }

        $now = CarbonImmutable::now()->utc();

        $affected = DB::connection('tenant')
            ->table('usage_counters')
            ->where('resource', $counter->resource)
            ->where('period_start', $counter->period_start)
            ->where(function ($query) use ($quantity): void {
                // Unlimited always matches. Otherwise the ceiling is checked
                // against the row's CURRENT value, inside the same statement
                // that changes it.
                $query->whereNull('allowance_snapshot')
                    ->orWhereRaw('used + ? <= allowance_snapshot', [$quantity]);
            })
            ->update([
                'used' => DB::raw('used + '.$quantity),
                'last_activity_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    /**
     * Adds to the total WITHOUT any ceiling — for metered resources.
     *
     * Tokens and message counts are recorded exactly and refuse nothing, so
     * "used" may legitimately exceed a stated allowance. That is the honest
     * record of what happened, and it is what the manager's dashboard and any
     * later billing conversation need (§3).
     */
    public function record(UsageCounter $counter, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        $now = CarbonImmutable::now()->utc();

        DB::connection('tenant')
            ->table('usage_counters')
            ->where('resource', $counter->resource)
            ->where('period_start', $counter->period_start)
            ->update([
                'used' => DB::raw('used + '.$quantity),
                'last_activity_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * Gives back what was spent, when the work it was spent on did not happen.
     *
     * Floored at zero by the statement itself: `GREATEST(used - n, 0)`. A
     * refund larger than the total would otherwise wrap an unsigned column and
     * produce an enormous number — the one arithmetic mistake here that would
     * lock a center out of a feature they have barely used.
     */
    public function refund(UsageCounter $counter, int $quantity): void
    {
        if ($quantity < 1) {
            return;
        }

        DB::connection('tenant')
            ->table('usage_counters')
            ->where('resource', $counter->resource)
            ->where('period_start', $counter->period_start)
            ->update([
                'used' => DB::raw('GREATEST(CAST(used AS SIGNED) - '.$quantity.', 0)'),
                'updated_at' => CarbonImmutable::now()->utc(),
            ]);
    }

    private function find(string $resource, UsagePeriod $period): ?UsageCounter
    {
        /** @var UsageCounter|null $counter */
        $counter = UsageCounter::query()
            ->where('resource', $resource)
            ->where('period_start', $period->start)
            ->first();

        return $counter;
    }
}
