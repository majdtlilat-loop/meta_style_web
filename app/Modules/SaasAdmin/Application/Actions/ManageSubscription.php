<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionHistory;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Immediate subscription changes made by a Super Admin.
 *
 * Every change locks the subscription, re-snapshots the commercial terms it
 * now runs on (plan, cycle, price, currency, name), appends one
 * `subscription_history` row and one audit entry, and invalidates the
 * center's entitlements. Scheduling a change for later stays available through
 * {@see ScheduleSubscriptionPlanChange}; this is the "now" path.
 *
 * Suspend, resume and cancel are lifecycle changes of the CENTER and go
 * through {@see ChangeTenantLifecycle}, which keeps both states in step.
 */
final class ManageSubscription
{
    public function __construct(
        private readonly Audit $audit,
        private readonly Entitlements $entitlements,
        private readonly PlatformNotifier $notifier,
    ) {}

    /** Change the plan and/or billing cycle, effective immediately. */
    public function changePlan(Subscription $subscription, Plan $plan, string $cycle, Actor $actor, string $reason): Subscription
    {
        $reason = $this->reason($reason);
        if (! $plan->is_active) {
            throw new DomainException(__('sadmin_subscriptions.errors.plan_inactive'));
        }
        $price = $plan->priceFor($cycle);
        if ($price === null) {
            throw new DomainException(__('sadmin_subscriptions.errors.cycle_not_offered'));
        }

        $event = $subscription->plan_id === $plan->id ? 'cycle_changed' : 'plan_changed';

        return $this->mutate($subscription, $actor, $reason, $event, function (Subscription $locked) use ($plan, $cycle, $price): array {
            if ($locked->plan_id === $plan->id && $locked->billing_period_snapshot === $cycle) {
                throw new DomainException(__('sadmin_subscriptions.errors.no_change'));
            }
            $values = [
                'plan_id' => $plan->id,
                'price_minor_snapshot' => $price,
                'currency_snapshot' => $plan->currency,
                'billing_period_snapshot' => $cycle,
                'plan_name_snapshot' => $plan->name->all(),
            ];
            // A running paid period restarts on the new terms; a trial keeps
            // its own dates.
            if ($locked->status === SubscriptionStatus::Active) {
                $values['current_period_start'] = now();
                $values['current_period_end'] = $this->periodEnd(now(), $cycle);
            }

            return $values;
        });
    }

    /** Ends a trial (or revives an expired one) as a paid, active subscription. */
    public function activate(Subscription $subscription, string $cycle, ?Carbon $startsAt, Actor $actor, string $reason): Subscription
    {
        $reason = $this->reason($reason);
        $plan = $subscription->plan;
        $price = $plan?->priceFor($cycle);
        if (! $plan instanceof Plan || $price === null) {
            throw new DomainException(__('sadmin_subscriptions.errors.cycle_not_offered'));
        }
        $start = $startsAt ?? now();

        $updated = $this->mutate($subscription, $actor, $reason, 'activated', function (Subscription $locked) use ($plan, $cycle, $price, $start): array {
            if (! in_array($locked->effectiveStatus(), [SubscriptionStatus::Trialing, SubscriptionStatus::Expired, SubscriptionStatus::PastDue], true)) {
                throw new DomainException(__('sadmin_subscriptions.errors.cannot_activate'));
            }

            return [
                'status' => SubscriptionStatus::Active,
                'price_minor_snapshot' => $price,
                'currency_snapshot' => $plan->currency,
                'billing_period_snapshot' => $cycle,
                'plan_name_snapshot' => $plan->name->all(),
                'trial_ends_at' => $locked->status === SubscriptionStatus::Trialing ? min($locked->trial_ends_at ?? now(), now()) : $locked->trial_ends_at,
                'current_period_start' => $start,
                'current_period_end' => $this->periodEnd($start, $cycle),
                'grace_ends_at' => null,
            ];
        });

        // An expired trial had also closed the center's access.
        TenantModel::query()->whereKey($updated->tenant_id)->where('status', 'active')->update(['suspended_at' => null]);

        return $updated;
    }

    /** Moves the trial end date — later to extend, earlier to shorten. */
    public function extendTrial(Subscription $subscription, Carbon $until, Actor $actor, string $reason): Subscription
    {
        $reason = $this->reason($reason);
        if ($until->isPast()) {
            throw new DomainException(__('sadmin_subscriptions.errors.future_date'));
        }

        return $this->mutate($subscription, $actor, $reason, 'trial_extended', function (Subscription $locked) use ($until): array {
            if (! in_array($locked->effectiveStatus(), [SubscriptionStatus::Trialing, SubscriptionStatus::Expired], true)) {
                throw new DomainException(__('sadmin_subscriptions.errors.not_trial'));
            }

            return [
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => $until,
                'current_period_end' => $until,
            ];
        }, ['trial_ends_at' => $until->toIso8601String()]);
    }

    /** Sets the date the current paid period renews (or falls due). */
    public function setRenewalDate(Subscription $subscription, Carbon $periodEnd, Actor $actor, string $reason): Subscription
    {
        $reason = $this->reason($reason);
        if ($periodEnd->isPast()) {
            throw new DomainException(__('sadmin_subscriptions.errors.future_date'));
        }

        return $this->mutate($subscription, $actor, $reason, 'renewal_changed', function (Subscription $locked) use ($periodEnd): array {
            if ($locked->status->isTerminal()) {
                throw new DomainException(__('sadmin_subscriptions.errors.terminal'));
            }

            return ['current_period_end' => $periodEnd];
        }, ['current_period_end' => $periodEnd->toIso8601String()]);
    }

    public function periodEnd(Carbon $start, string $cycle): Carbon
    {
        return $cycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
    }

    /**
     * @param  callable(Subscription): array<string, mixed>  $change
     * @param  array<string, mixed>  $details
     */
    private function mutate(Subscription $subscription, Actor $actor, string $reason, string $event, callable $change, array $details = []): Subscription
    {
        [$updated, $before] = DB::connection('control')->transaction(function () use ($subscription, $actor, $reason, $event, $change, $details): array {
            /** @var Subscription|null $locked */
            $locked = Subscription::query()->lockForUpdate()->find($subscription->id);
            if (! $locked instanceof Subscription) {
                throw new DomainException(__('sadmin_subscriptions.errors.missing'));
            }
            $before = [
                'plan_id' => $locked->plan_id,
                'cycle' => $locked->billing_period_snapshot,
                'status' => $locked->status->value,
                'price_minor' => $locked->price_minor_snapshot,
                'currency' => $locked->currency_snapshot,
                'trial_ends_at' => $locked->trial_ends_at?->toIso8601String(),
                'current_period_end' => $locked->current_period_end?->toIso8601String(),
            ];

            $locked->forceFill($change($locked))->save();
            SubscriptionHistory::record($locked, $event, $actor, $reason, $before, $details);

            return [$locked, $before];
        });

        $this->entitlements->invalidate($updated->tenant_id);
        $tenantName = (string) TenantModel::query()->whereKey($updated->tenant_id)->value('name');

        $this->audit->recordForTenant($updated->tenant_id, new AuditEvent(
            action: 'platform.subscription.'.$event,
            category: AuditCategory::Finance,
            actor: $actor,
            targetType: Subscription::class,
            targetId: (string) $updated->id,
            targetLabel: $tenantName,
            before: $before,
            after: [
                'plan_id' => $updated->plan_id,
                'cycle' => $updated->billing_period_snapshot,
                'status' => $updated->status->value,
                'price_minor' => $updated->price_minor_snapshot,
                'currency' => $updated->currency_snapshot,
                'trial_ends_at' => $updated->trial_ends_at?->toIso8601String(),
                'current_period_end' => $updated->current_period_end?->toIso8601String(),
            ],
            reason: $reason,
        ));

        $this->notifier->notify('subscriptions.changed', 'info', 'subscription_'.$event, ['center' => $tenantName], $updated->tenant_id, route('superadmin.centers.show', ['tenant' => $updated->tenant_id, 'tab' => 'subscription'], false));

        return $updated;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('sadmin_subscriptions.errors.reason'));
        }

        return $reason;
    }
}
