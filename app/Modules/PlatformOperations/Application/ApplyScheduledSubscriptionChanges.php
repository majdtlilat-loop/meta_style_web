<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use Illuminate\Support\Facades\DB;

final class ApplyScheduledSubscriptionChanges
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function __invoke(): int
    {
        $applied = 0;
        SubscriptionScheduledChange::query()->where('status', 'scheduled')->where('effective_at', '<=', now())->orderBy('id')->chunkById(100, function ($changes) use (&$applied): void {
            foreach ($changes as $change) {
                $tenantId = DB::connection('control')->transaction(function () use ($change): ?string {
                    /** @var SubscriptionScheduledChange|null $locked */
                    $locked = SubscriptionScheduledChange::query()->lockForUpdate()->find($change->id);
                    if (! $locked instanceof SubscriptionScheduledChange || $locked->status !== 'scheduled') {
                        return null;
                    }
                    /** @var Subscription|null $subscription */
                    $subscription = Subscription::query()->lockForUpdate()->find($locked->subscription_id);
                    /** @var Plan|null $plan */
                    $plan = Plan::query()->find($locked->target_plan_id);
                    if (! $subscription instanceof Subscription || ! $plan instanceof Plan) {
                        $locked->update(['status' => 'failed']);

                        return null;
                    }
                    $subscription->forceFill(['plan_id' => $plan->id, 'price_minor_snapshot' => $plan->price_minor, 'currency_snapshot' => $plan->currency, 'billing_period_snapshot' => $plan->billing_period, 'plan_name_snapshot' => $plan->name->all()])->save();
                    $locked->forceFill(['status' => 'applied', 'applied_at' => now()])->save();

                    return $subscription->tenant_id;
                });
                if ($tenantId !== null) {
                    $this->entitlements->invalidate($tenantId);
                    $applied++;
                }
            }
        });

        return $applied;
    }
}
