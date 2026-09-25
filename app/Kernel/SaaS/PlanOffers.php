<?php

declare(strict_types=1);

namespace App\Kernel\SaaS;

use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\Usage\Models\PlanLimit;
use App\Kernel\Usage\UsageCatalog;

/**
 * The plans a center could move to, as data — never as plan names in code.
 *
 * "Available from X" is derived from the catalog alone: the public, active
 * plans in their commercial order whose EFFECTIVE feature set (entitlements
 * plus their dependency closure) contains the feature. The ordering is total
 * (sort order, then monthly-equivalent price, then id), so the answer is the
 * same on every request for the same catalog.
 *
 * Read-only and memoised per request. A center still reaches a feature only
 * when its own entitlements say so; this class decides nothing.
 */
final class PlanOffers
{
    /** @var list<Plan>|null */
    private ?array $plans = null;

    /** @var array<int, list<string>> */
    private array $codes = [];

    /** @var array<int, array<string, int|null>> */
    private array $limits = [];

    public function __construct(
        private readonly EntitlementCatalog $catalog,
        private readonly UsageCatalog $usage,
    ) {}

    /**
     * Public, active plans in commercial order.
     *
     * @return list<Plan>
     */
    public function all(): array
    {
        if ($this->plans !== null) {
            return $this->plans;
        }

        $plans = Plan::query()
            ->with('entitlements')
            ->where('is_public', true)
            ->where('is_active', true)
            ->get()
            ->all();

        return $this->plans = self::inCommercialOrder($plans);
    }

    /**
     * The commercial order, as a pure function of the plans themselves: sort
     * order, then the monthly-equivalent price (yearly / 12 when there is no
     * monthly price; unpriced plans last), then id. Total, so the same catalog
     * always yields the same order whatever order the database returned.
     *
     * @param  list<Plan>  $plans
     * @return list<Plan>
     */
    public static function inCommercialOrder(array $plans): array
    {
        usort($plans, static fn (Plan $a, Plan $b): int => [(int) $a->sort_order, self::monthlyEquivalent($a), (int) $a->getKey()]
            <=> [(int) $b->sort_order, self::monthlyEquivalent($b), (int) $b->getKey()]);

        return $plans;
    }

    /**
     * The features a plan really gives, dependencies included.
     *
     * @return list<string>
     */
    public function effectiveCodes(Plan $plan): array
    {
        $key = (int) $plan->getKey();

        return $this->codes[$key] ??= $this->catalog->applyDependencies(
            $plan->relationLoaded('entitlements')
                ? $plan->entitlements->pluck('entitlement')->map(static fn (mixed $code): string => (string) $code)->values()->all()
                : $plan->entitlementCodes()
        );
    }

    /**
     * Public plans that include a feature, in commercial order.
     *
     * @return list<Plan>
     */
    public function including(string $feature): array
    {
        if (! $this->catalog->has($feature)) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            fn (Plan $plan): bool => in_array($feature, $this->effectiveCodes($plan), true),
        ));
    }

    /** The first public plan, in commercial order, that includes a feature — other than the given one. */
    public function lowestIncluding(string $feature, ?int $exceptPlanId = null): ?Plan
    {
        foreach ($this->including($feature) as $plan) {
            if ($exceptPlanId === null || (int) $plan->getKey() !== $exceptPlanId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Enforced usage allowances of a plan: resource → allowance (null = unlimited).
     *
     * @return array<string, int|null>
     */
    public function limits(Plan $plan): array
    {
        $key = (int) $plan->getKey();
        if (array_key_exists($key, $this->limits)) {
            return $this->limits[$key];
        }

        $rows = PlanLimit::query()->where('plan_id', $plan->getKey())->pluck('allowance', 'resource')->all();

        $limits = [];
        foreach ($this->usage->codes() as $resource) {
            if (! $this->usage->isEnforced($resource)) {
                continue;
            }
            $limits[$resource] = array_key_exists($resource, $rows)
                ? ($rows[$resource] === null ? null : (int) $rows[$resource])
                : $this->usage->systemDefault($resource);
        }

        return $this->limits[$key] = $limits;
    }

    /** A plan's price per month, for ordering only; plans without a price sort last. */
    private static function monthlyEquivalent(Plan $plan): int
    {
        $monthly = $plan->priceFor('monthly');
        if ($monthly !== null) {
            return $monthly;
        }

        $yearly = $plan->priceFor('yearly');

        return $yearly !== null ? intdiv($yearly, 12) : PHP_INT_MAX;
    }
}
