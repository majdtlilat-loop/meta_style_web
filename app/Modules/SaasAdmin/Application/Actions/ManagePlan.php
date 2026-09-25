<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Usage\Models\PlanLimit;
use App\Kernel\Usage\UsageCatalog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits plans: one plan, priced per billing cycle.
 *
 * A plan is sold monthly, yearly or both, in ONE currency. The legacy
 * `price_minor` / `billing_period` pair stays as the plan's primary price
 * (monthly when offered, otherwise yearly) so every older reader keeps
 * working. Every price change closes the previous `plan_price_history` row and
 * opens a new one per cycle; subscriptions keep their own snapshot, so a price
 * change never rewrites what a center already agreed to pay.
 */
final class ManagePlan
{
    public function __construct(private readonly Audit $audit, private readonly PlatformCurrencies $currencies) {}

    /**
     * @param  array<string,string>  $name
     * @param  array<string,string>  $description
     * @param  list<string>  $entitlements
     */
    public function save(
        ?Plan $plan,
        string $code,
        array $name,
        array $description,
        ?int $monthlyMinor,
        ?int $yearlyMinor,
        string $currency,
        ?int $trialDays,
        bool $isPublic,
        bool $isFeatured,
        int $sortOrder,
        array $entitlements,
        Actor $actor,
        string $reason,
    ): Plan {
        $code = mb_strtolower(trim($code));
        $currency = mb_strtoupper(trim($currency));
        $reason = trim($reason);

        if (($name['en'] ?? '') === '' || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) !== 1 || $reason === '') {
            throw new DomainException(__('sadmin_plans.errors.identity'));
        }
        if ($monthlyMinor === null && $yearlyMinor === null) {
            throw new DomainException(__('sadmin_plans.errors.no_cycle'));
        }
        if (($monthlyMinor !== null && $monthlyMinor < 0) || ($yearlyMinor !== null && $yearlyMinor < 0)) {
            throw new DomainException(__('sadmin_plans.errors.price'));
        }
        $currentCurrency = $plan?->currency;
        if (! in_array($currency, $this->currencies->enabledCodes(), true) && $currency !== $currentCurrency) {
            throw new DomainException(__('sadmin_plans.errors.currency'));
        }
        if (Plan::query()->where('code', $code)->when($plan instanceof Plan, fn ($q) => $q->whereKeyNot($plan?->id))->exists()) {
            throw new DomainException(__('sadmin_plans.errors.code_taken'));
        }

        $primaryCycle = $monthlyMinor !== null ? 'monthly' : 'yearly';
        $primaryPrice = (int) ($monthlyMinor ?? $yearlyMinor);

        $before = $plan instanceof Plan ? $this->snapshot($plan) : null;

        DB::connection('control')->transaction(function () use (&$plan, $code, $name, $description, $monthlyMinor, $yearlyMinor, $currency, $primaryCycle, $primaryPrice, $trialDays, $isPublic, $isFeatured, $sortOrder, $entitlements, $actor, $reason): void {
            $new = ! $plan instanceof Plan;
            $changed = [];
            foreach (['monthly' => $monthlyMinor, 'yearly' => $yearlyMinor] as $cycle => $price) {
                if ($new || $plan->priceFor($cycle) !== $price || $plan->currency !== $currency) {
                    $changed[$cycle] = $price;
                }
            }

            $values = [
                'code' => $code, 'name' => $name, 'description' => $description,
                'price_minor' => $primaryPrice, 'billing_period' => $primaryCycle,
                'monthly_price_minor' => $monthlyMinor, 'yearly_price_minor' => $yearlyMinor,
                'currency' => $currency, 'trial_days' => $trialDays,
                'is_public' => $isPublic, 'is_featured' => $isFeatured, 'sort_order' => $sortOrder,
            ];

            if ($new) {
                $plan = Plan::query()->create($values + ['is_active' => true]);
            } else {
                $plan->forceFill($values)->save();
            }

            foreach ($changed as $cycle => $price) {
                DB::connection('control')->table('plan_price_history')
                    ->where('plan_id', $plan->id)->where('billing_period', $cycle)->whereNull('effective_until')
                    ->update(['effective_until' => now(), 'updated_at' => now()]);
                if ($price !== null) {
                    DB::connection('control')->table('plan_price_history')->insert([
                        'plan_id' => $plan->id, 'price_minor' => $price, 'currency' => $currency, 'billing_period' => $cycle,
                        'effective_from' => now(), 'effective_until' => null, 'changed_by_id' => $actor->id,
                        'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $plan->syncEntitlements($entitlements);
        });

        /** @var Plan $plan */
        $this->invalidateSubscribers($plan);
        $this->audit->record(new AuditEvent(
            action: $before === null ? 'platform.plan.created' : 'platform.plan.updated',
            category: AuditCategory::Finance,
            actor: $actor,
            targetType: Plan::class,
            targetId: (string) $plan->id,
            targetLabel: $plan->code,
            before: $before,
            after: $this->snapshot($plan->refresh()) + ['entitlements' => $entitlements],
            reason: $reason,
        ));

        return $plan;
    }

    public function setActive(Plan $plan, bool $active, Actor $actor, string $reason): Plan
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('sadmin_plans.errors.reason'));
        }
        if (! $active && PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE) === $plan->code) {
            throw new DomainException(__('sadmin_plans.errors.default_plan'));
        }

        $before = ['is_active' => $plan->is_active, 'is_public' => $plan->is_public];
        $plan->forceFill(['is_active' => $active, 'is_public' => $active ? $plan->is_public : false])->save();

        $this->audit->record(new AuditEvent(
            action: $active ? 'platform.plan.restored' : 'platform.plan.archived',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: Plan::class,
            targetId: (string) $plan->id,
            targetLabel: $plan->code,
            before: $before,
            after: ['is_active' => $plan->is_active, 'is_public' => $plan->is_public],
            reason: $reason,
        ));

        return $plan->refresh();
    }

    /**
     * What the plan includes of each metered resource.
     *
     * `default` removes the plan's own figure (the platform default applies),
     * null means unlimited, a number is the allowance. Read live by the usage
     * kernel's allowance resolver, so a change applies to subscribers at once.
     *
     * @param  array<string, int|string|null>  $limits
     */
    public function setLimits(Plan $plan, array $limits, Actor $actor, string $reason): void
    {
        $catalog = app(UsageCatalog::class);
        $before = PlanLimit::query()->where('plan_id', $plan->id)->pluck('allowance', 'resource')->all();
        $after = [];

        DB::connection('control')->transaction(function () use ($plan, $limits, $catalog, &$after): void {
            foreach ($limits as $resource => $value) {
                if (! $catalog->has($resource)) {
                    continue;
                }
                if ($value === 'default') {
                    PlanLimit::query()->where('plan_id', $plan->id)->where('resource', $resource)->delete();

                    continue;
                }
                if ($value !== null && (! is_int($value) || $value < 0)) {
                    throw new DomainException(__('sadmin_plans.errors.limit'));
                }
                PlanLimit::query()->updateOrCreate(['plan_id' => $plan->id, 'resource' => $resource], ['allowance' => $value]);
                $after[$resource] = $value;
            }
        });

        if ($before == $after) {
            return;
        }

        $this->audit->record(new AuditEvent(
            action: 'platform.plan.limits.updated',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: Plan::class,
            targetId: (string) $plan->id,
            targetLabel: $plan->code,
            before: $before,
            after: $after,
            reason: trim($reason),
        ));
    }

    /** @return array<string, mixed> */
    private function snapshot(Plan $plan): array
    {
        return [
            'code' => $plan->code,
            'name' => $plan->name->all(),
            'description' => $plan->description?->all(),
            'monthly_price_minor' => $plan->priceFor('monthly'),
            'yearly_price_minor' => $plan->priceFor('yearly'),
            'currency' => $plan->currency,
            'trial_days' => $plan->trial_days,
            'is_public' => $plan->is_public,
            'is_featured' => $plan->is_featured,
            'is_active' => $plan->is_active,
            'sort_order' => $plan->sort_order,
        ];
    }

    private function invalidateSubscribers(Plan $plan): void
    {
        $tenantIds = Subscription::query()->where('plan_id', $plan->id)->pluck('tenant_id');
        TenantModel::query()->whereIn('id', $tenantIds)->increment('entitlements_version');
    }
}
