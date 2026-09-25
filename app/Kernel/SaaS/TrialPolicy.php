<?php

declare(strict_types=1);

namespace App\Kernel\SaaS;

use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Support\Carbon;

/**
 * How long a new center's free trial lasts, and where that number comes from.
 *
 * Precedence, most specific first:
 *
 *   1. the tenant's own override            (a support courtesy, per center)
 *   2. the plan's `trial_days`              (a shorter trial on a cheap plan)
 *   3. the platform setting                 (Super Admin, no deploy)
 *   4. the bootstrap default in config      (only until the setting is seeded)
 *
 * No business logic anywhere contains a literal number of days. Changing the
 * default trial from 14 to 21 is a settings change, not a release
 * (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §9).
 */
final class TrialPolicy
{
    public function defaultDays(): int
    {
        $configured = PlatformSetting::get(PlatformSetting::DEFAULT_TRIAL_DAYS);

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $bootstrap = config('metastyle.saas.default_trial_days');

        return is_numeric($bootstrap) ? (int) $bootstrap : 14;
    }

    public function daysFor(?Plan $plan = null, ?TenantModel $tenant = null): int
    {
        $override = $tenant?->trial_days_override;

        if (is_numeric($override) && (int) $override > 0) {
            return (int) $override;
        }

        if ($plan?->trial_days !== null && $plan->trial_days > 0) {
            return $plan->trial_days;
        }

        return $this->defaultDays();
    }

    /**
     * Opens a trial subscription for a tenant.
     */
    public function start(TenantModel $tenant, Plan $plan, ?Carbon $now = null): Subscription
    {
        $now ??= Carbon::now();
        $days = $this->daysFor($plan, $tenant);

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->getTenantKey()],
            [
                'plan_id' => $plan->id,
                'price_minor_snapshot' => $plan->price_minor,
                'currency_snapshot' => $plan->currency,
                'billing_period_snapshot' => $plan->billing_period,
                'plan_name_snapshot' => $plan->name->all(),
                'status' => SubscriptionStatus::Trialing,
                'trial_starts_at' => $now,
                'trial_ends_at' => $now->copy()->addDays($days),
                'current_period_start' => $now,
                'current_period_end' => $now->copy()->addDays($days),
            ],
        );

        return $subscription;
    }

    /**
     * Marks lapsed trials expired.
     *
     * A scheduled sweep keeps the stored status honest, but nothing depends on
     * it having run: {@see Subscription::effectiveStatus()} computes expiry
     * from the timestamp on every read, so a trial that lapsed a minute ago is
     * already refused. The sweep exists for reporting and for the reminder
     * jobs, not for enforcement.
     *
     * @return int number of subscriptions expired
     */
    public function expireLapsedTrials(?Carbon $now = null): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now ?? Carbon::now())
            ->update(['status' => SubscriptionStatus::Expired->value]);
    }
}
