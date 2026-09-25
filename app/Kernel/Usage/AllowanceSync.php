<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Keeps each center's allowance SNAPSHOT in step with the control plane.
 *
 * The snapshot exists so a quota decision never crosses databases (§5). The
 * cost of that is a copy, and a copy can be stale: Super Admin raises a
 * center's allowance, and the write to the tenant database fails, or the
 * process dies between the two. This is the repair — the same after-commit
 * shape as the benefit reconcilers, for the same reason (ADR-061).
 *
 * ## The rules it enforces (docs/26-USAGE-QUOTAS.md §6)
 *
 *   increase            applies IMMEDIATELY. A center that has just paid for
 *                       more must not wait for a billing boundary.
 *   finite → unlimited  applies immediately. Also an increase.
 *   decrease            NEXT PERIOD ONLY. A center part-way through a month
 *                       they have paid for does not get cut off because of a
 *                       pricing change.
 *   unlimited → finite  next period only. Also a decrease.
 *   enforce_immediately applies a decrease now — the audited escape hatch for
 *                       abuse or a compromised account, and nothing else.
 *
 * A deferred decrease needs no bookkeeping: the NEXT period's counter row is
 * created from a fresh resolution, so it simply starts with the new number.
 * That is also why a deferred decrease must not record the version — recording
 * it would mark the change "applied" and the decrease would never arrive.
 *
 * ## Idempotent, and never lowers by accident
 *
 * Running it twice changes nothing the second time: an already-applied override
 * matches on `allowance_version`, and a plan-sourced allowance matches on
 * value. The one direction it will never take on its own is DOWN — every path
 * that lowers a live snapshot is guarded by `enforce_immediately` (§8).
 */
final class AllowanceSync implements Reconciler
{
    public function __construct(
        private readonly UsageCatalog $catalog,
        private readonly Allowances $allowances,
        private readonly TenantContext $tenants,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'usage allowances';
    }

    /**
     * `$since` is deliberately unused.
     *
     * Every other reconciler replays FACTS from a window — payments, completed
     * visits — and a window bounds the work. This one repairs a handful of rows
     * that represent the CURRENT period, and "an allowance changed three weeks
     * ago and never reached the tenant" is exactly the case that must still be
     * fixed. A window here would make the repair expire.
     */
    public function reconcile(CarbonImmutable $since): int
    {
        unset($since);

        $tenantId = $this->tenants->require()->id;
        $period = UsagePeriod::resolve($this->config, $tenantId);

        $repaired = 0;

        foreach ($this->catalog->codes() as $resource) {
            /** @var UsageCounter|null $counter */
            $counter = UsageCounter::query()
                ->where('resource', $resource)
                ->where('period_start', $period->start)
                ->first();

            if (! $counter instanceof UsageCounter) {
                /*
                 * No row means this center has not used the resource this
                 * period. There is nothing to correct — and creating one here
                 * would give every center a full set of counters whether or not
                 * they ever touch the feature.
                 */
                continue;
            }

            if ($this->apply($counter, $this->allowances->resolve($tenantId, $resource))) {
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * @return bool whether the snapshot was changed
     */
    private function apply(UsageCounter $counter, ResolvedAllowance $resolved): bool
    {
        if ($this->alreadyApplied($counter, $resolved)) {
            return false;
        }

        $isIncrease = $resolved->isIncreaseFrom($counter->allowance_snapshot);

        if (! $isIncrease && ! $resolved->enforceImmediately) {
            // A decrease, waiting for the next period. Deliberately records
            // NOTHING: marking it applied would cancel it.
            return false;
        }

        $counter->forceFill([
            'allowance_snapshot' => $resolved->allowance,
            'allowance_version' => $resolved->version,
        ])->save();

        return true;
    }

    /**
     * Has this exact control-plane state already been copied down?
     *
     * Two ways to know, and they are not interchangeable:
     *
     *   - an OVERRIDE carries a version, and matching versions mean the
     *     snapshot came from this very row. Value comparison could not tell a
     *     stale copy from a decrease correctly waiting for next period.
     *   - a PLAN or DEFAULT allowance has no version, so the only available
     *     test is the value itself — which is sufficient, because nothing about
     *     those sources needs the deferred-decrease distinction to survive
     *     across runs.
     */
    private function alreadyApplied(UsageCounter $counter, ResolvedAllowance $resolved): bool
    {
        if ($resolved->version !== null) {
            return $counter->allowance_version === $resolved->version;
        }

        return $counter->allowance_version === null
            && $counter->allowance_snapshot === $resolved->allowance;
    }
}
