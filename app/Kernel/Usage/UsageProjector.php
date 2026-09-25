<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Models\TenantUsageProjection;
use App\Kernel\Usage\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Copies one center's current-period usage into the control plane, for Super
 * Admin reporting.
 *
 * ## Why a copy exists at all
 *
 * The authoritative counters are in a hundred separate databases, which is what
 * makes a quota check fast and isolated — and what makes "show me every center
 * near its limit" impossible to answer without opening all hundred. So the
 * platform keeps a lagging copy (docs/26-USAGE-QUOTAS.md §9).
 *
 * ## What the copy may never be used for
 *
 * A DECISION. Quota consumption reads and writes the tenant's own counter row
 * and nothing else. If this table were ever consulted to decide whether a run
 * may proceed, every center would be judged against a snapshot that is minutes
 * old — reintroducing exactly the race the tenant-side counter removes, and
 * doing it across a database boundary where no lock can help.
 *
 * An architecture test enforces the direction: nothing outside this class
 * writes `tenant_usage_projections`, and nothing anywhere reads it to gate.
 *
 * ## Idempotent
 *
 * One row per tenant, resource and period, upserted. Re-projecting an unchanged
 * center rewrites the same numbers; re-running after a failure completes what
 * was missed. Nothing accumulates.
 */
final class UsageProjector
{
    public function __construct(
        private readonly UsageCatalog $catalog,
        private readonly TenantContext $tenants,
        private readonly Config $config,
    ) {}

    /**
     * Projects every metered resource for the bound tenant's current period.
     *
     * @return int how many rows were written
     */
    public function project(?CarbonImmutable $at = null): int
    {
        $tenantId = $this->tenants->require()->id;
        $at = ($at ?? CarbonImmutable::now())->utc();
        $period = UsagePeriod::resolve($this->config, $tenantId, $at);

        $thresholds = $this->catalog->thresholds();
        $written = 0;

        foreach ($this->catalog->codes() as $resource) {
            /** @var UsageCounter|null $counter */
            $counter = UsageCounter::query()
                ->where('resource', $resource)
                ->where('period_start', $period->start)
                ->first();

            if (! $counter instanceof UsageCounter) {
                /*
                 * Nothing used, so nothing to report. Deliberately NOT a zero
                 * row: a projection table containing a row per resource per
                 * center per month whether or not the feature was touched is
                 * mostly noise, and "no row" already means "no usage".
                 */
                continue;
            }

            $percent = $counter->percent();

            TenantUsageProjection::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'resource' => $resource,
                    'period_start' => $period->start,
                ],
                [
                    'period_end' => $period->end,
                    'used' => $counter->used,
                    // Both null together when unlimited. A percentage of
                    // unlimited does not exist and is not stored as 0 (§10).
                    'allowance' => $counter->allowance_snapshot,
                    'percent' => $percent,
                    'status' => UsageStatus::forPercent($percent, $thresholds),
                    'last_activity_at' => $counter->last_activity_at,
                    'projected_at' => $at,
                ],
            );

            $written++;
        }

        return $written;
    }
}
