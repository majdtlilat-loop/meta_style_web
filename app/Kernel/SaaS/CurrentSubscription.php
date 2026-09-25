<?php

declare(strict_types=1);

namespace App\Kernel\SaaS;

use App\Kernel\Entitlements\TenantAccessLevel;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use App\Kernel\Tenancy\Contracts\TenantContext;

/**
 * The bound center's own subscription, read from the control plane.
 *
 * Memoised per request: the Manager shell, the dashboard and the plan page all
 * ask, and a subscription does not change mid-request. Never used to
 * authorise anything — entitlements answer "may this center use X", this
 * answers "what does the center see about its plan".
 */
final class CurrentSubscription
{
    /** @var array<string, SubscriptionSummary|null> */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function summary(?string $tenantId = null): ?SubscriptionSummary
    {
        $tenantId ??= $this->tenants->id();
        if ($tenantId === null) {
            return null;
        }

        if (array_key_exists($tenantId, $this->memo)) {
            return $this->memo[$tenantId];
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->with('plan')->where('tenant_id', $tenantId)->first();
        if ($subscription === null) {
            return $this->memo[$tenantId] = null;
        }

        /** @var SubscriptionScheduledChange|null $change */
        $change = SubscriptionScheduledChange::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', 'scheduled')
            ->orderBy('effective_at')
            ->first();

        $status = $subscription->effectiveStatus();
        $snapshot = is_array($subscription->plan_name_snapshot) ? $subscription->plan_name_snapshot : [];
        // Empty names are dropped, so a missing translation falls back cleanly.
        $names = array_filter($snapshot !== [] ? $snapshot : ($subscription->plan?->name->all() ?? []));
        $cycle = $subscription->billing_period_snapshot ?: null;

        return $this->memo[$tenantId] = new SubscriptionSummary(
            plan: $subscription->plan,
            planName: $names,
            status: $status,
            accessLevel: TenantAccessLevel::fromSubscription($status),
            cycle: $cycle,
            priceMinor: $subscription->price_minor_snapshot,
            currency: $subscription->currency_snapshot ?: null,
            trialEndsAt: $status->isTrial() ? $subscription->trial_ends_at : null,
            trialDaysLeft: $status->isTrial() ? $subscription->trialDaysLeft() : null,
            renewsAt: $subscription->current_period_end,
            graceEndsAt: $subscription->grace_ends_at,
            scheduledPlan: $change !== null && $change->target_plan_id !== null
                ? Models\Plan::query()->find($change->target_plan_id)
                : null,
            scheduledAt: $change?->effective_at,
        );
    }
}
