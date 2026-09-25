<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\SaaS\Models\Plan;
use DomainException;
use Illuminate\Support\Facades\DB;

final class UpdatePlanPricing
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(Plan $plan, int $priceMinor, string $currency, string $period, Actor $actor, string $reason): Plan
    {
        $currency = mb_strtoupper(trim($currency));
        if ($priceMinor < 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! in_array($period, ['monthly', 'quarterly', 'yearly'], true) || trim($reason) === '') {
            throw new DomainException('Valid price, currency, billing period, and reason are required.');
        }
        $before = ['price_minor' => $plan->price_minor, 'currency' => $plan->currency, 'billing_period' => $plan->billing_period];
        DB::connection('control')->transaction(function () use ($plan, $priceMinor, $currency, $period, $actor, $reason): void {
            DB::connection('control')->table('plan_price_history')->where('plan_id', $plan->id)->whereNull('effective_until')->update(['effective_until' => now(), 'updated_at' => now()]);
            DB::connection('control')->table('plan_price_history')->insert(['plan_id' => $plan->id, 'price_minor' => $priceMinor, 'currency' => $currency, 'billing_period' => $period, 'effective_from' => now(), 'effective_until' => null, 'changed_by_id' => $actor->id, 'reason' => trim($reason), 'created_at' => now(), 'updated_at' => now()]);
            $plan->forceFill(['price_minor' => $priceMinor, 'currency' => $currency, 'billing_period' => $period])->save();
        });
        $this->audit->record(new AuditEvent(action: 'platform.plan.price.changed', category: AuditCategory::Finance, actor: $actor, targetType: Plan::class, targetId: (string) $plan->id, targetLabel: $plan->code, before: $before, after: ['price_minor' => $priceMinor, 'currency' => $currency, 'billing_period' => $period], reason: trim($reason)));

        return $plan->refresh();
    }
}
