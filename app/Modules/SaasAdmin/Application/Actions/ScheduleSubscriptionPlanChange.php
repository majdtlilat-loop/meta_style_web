<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use DomainException;
use Illuminate\Support\Carbon;

final class ScheduleSubscriptionPlanChange
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(Subscription $subscription, Plan $plan, Carbon $effectiveAt, Actor $actor, string $reason): SubscriptionScheduledChange
    {
        if ($effectiveAt->isPast() || trim($reason) === '') {
            throw new DomainException('A future effective date and reason are required.');
        }
        SubscriptionScheduledChange::query()->where('subscription_id', $subscription->id)->where('status', 'scheduled')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        /** @var SubscriptionScheduledChange $change */
        $change = SubscriptionScheduledChange::query()->create(['subscription_id' => $subscription->id, 'target_plan_id' => $plan->id, 'effective_at' => $effectiveAt, 'status' => 'scheduled', 'reason' => trim($reason), 'requested_by_id' => $actor->id]);
        $this->audit->recordForTenant($subscription->tenant_id, new AuditEvent(action: 'platform.subscription.plan_change.scheduled', category: AuditCategory::Finance, actor: $actor, targetType: Subscription::class, targetId: (string) $subscription->id, after: ['target_plan_id' => $plan->id, 'effective_at' => $effectiveAt->toIso8601String()], reason: trim($reason)));

        return $change;
    }
}
