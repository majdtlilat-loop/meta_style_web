<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Usage\Models\PlanLimit;
use App\Kernel\Usage\Models\TenantLimitOverride;

/**
 * Resolves a center's allowance from the control plane.
 *
 *     tenant override  →  plan limit  →  system default
 *
 * First match wins, and a match includes NULL: an override that says "unlimited"
 * is an answer, not a gap, so it stops the chain rather than falling through to
 * the plan's finite number (docs/26-USAGE-QUOTAS.md §4).
 *
 * ## Off the hot path, deliberately
 *
 * This reads the CONTROL database. It is called when a period's counter row is
 * first created and by the reconciler afterwards — never by a quota check,
 * which works entirely inside the tenant database against the snapshot this
 * produced (§5).
 */
final class Allowances
{
    public function __construct(private readonly UsageCatalog $catalog) {}

    public function resolve(string $tenantId, string $resource): ResolvedAllowance
    {
        $this->catalog->assertKnown($resource);

        /** @var TenantLimitOverride|null $override */
        $override = TenantLimitOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('resource', $resource)
            ->first();

        if ($override instanceof TenantLimitOverride) {
            return new ResolvedAllowance(
                allowance: $override->allowance,
                source: 'override',
                version: $override->version,
                enforceImmediately: $override->enforce_immediately,
            );
        }

        $planAllowance = $this->fromPlan($tenantId, $resource);

        if ($planAllowance !== false) {
            return new ResolvedAllowance($planAllowance, 'plan');
        }

        return new ResolvedAllowance($this->catalog->systemDefault($resource), 'default');
    }

    /**
     * The plan's allowance, or `false` when the plan says nothing at all.
     *
     * `false` rather than null, because NULL IS AN ANSWER here — it means the
     * plan grants unlimited. Collapsing "unlimited" and "unspecified" into one
     * return value would make a plan that deliberately sells unlimited fall
     * through to the system default and silently cap the center (§4).
     */
    private function fromPlan(string $tenantId, string $resource): int|null|false
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

        if (! $subscription instanceof Subscription) {
            return false;
        }

        /** @var PlanLimit|null $limit */
        $limit = PlanLimit::query()
            ->where('plan_id', $subscription->plan_id)
            ->where('resource', $resource)
            ->first();

        return $limit instanceof PlanLimit ? $limit->allowance : false;
    }
}
