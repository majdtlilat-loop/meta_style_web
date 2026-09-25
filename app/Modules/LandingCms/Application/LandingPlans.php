<?php

declare(strict_types=1);

namespace App\Modules\LandingCms\Application;

use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\TrialPolicy;
use App\Kernel\Usage\Models\PlanLimit;
use App\Kernel\Usage\UsageCatalog;

/**
 * The public plans, as the corporate site shows them.
 *
 * Read from the real commercial plans every time — the CMS never holds a
 * price or a feature list. A yearly saving is shown only when the yearly price
 * really is below twelve monthly payments.
 */
final class LandingPlans
{
    public function __construct(
        private readonly PlatformCurrencies $currencies,
        private readonly EntitlementCatalog $catalog,
        private readonly UsageCatalog $usage,
        private readonly TrialPolicy $trials,
    ) {}

    /**
     * @return array{plans: list<array<string, mixed>>, cycles: array{monthly: bool, yearly: bool}, comparison: list<array{label: string, rows: list<array{label: string, values: array<int, bool>}>}>, limits: list<array{label: string, values: array<int, string>}>}
     */
    public function build(string $locale): array
    {
        $plans = Plan::query()->with('entitlements')->where('is_public', true)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $limits = PlanLimit::query()->whereIn('plan_id', $plans->pluck('id'))->get()->groupBy('plan_id');
        $enforced = array_values(array_filter($this->usage->codes(), fn (string $code): bool => $this->usage->isEnforced($code)));

        $presented = [];
        foreach ($plans as $plan) {
            $codes = $plan->entitlements->pluck('entitlement')->filter(fn (string $key): bool => $this->catalog->has($key))->values()->all();
            $presented[] = [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name->get($locale),
                'description' => $plan->description?->get($locale) ?? '',
                'featured' => $plan->is_featured,
                'currency' => $plan->currency,
                'monthly' => $this->price($plan, 'monthly', $locale),
                'yearly' => $this->price($plan, 'yearly', $locale),
                'saving' => $plan->yearlySavingPercent(),
                'trial_days' => $this->trials->daysFor($plan),
                'features' => array_map(fn (string $key): string => (string) __('platform_labels.entitlement.'.$key, [], $locale), $codes),
                'register_url' => '/register?plan='.$plan->id,
            ];
        }

        $comparison = [];
        $categories = [];
        foreach ($this->catalog->keys() as $key) {
            $categories[$this->catalog->category($key)][] = $key;
        }
        foreach ($categories as $category => $keys) {
            $rows = [];
            foreach ($keys as $key) {
                $values = [];
                foreach ($plans as $plan) {
                    $values[$plan->id] = $plan->entitlements->contains('entitlement', $key);
                }
                if (in_array(true, $values, true)) {
                    $rows[] = ['label' => (string) __('platform_labels.entitlement.'.$key, [], $locale), 'values' => $values];
                }
            }
            if ($rows !== []) {
                $comparison[] = ['label' => (string) __('platform_labels.entitlement_category.'.$category, [], $locale), 'rows' => $rows];
            }
        }

        $limitRows = [];
        foreach ($enforced as $resource) {
            $values = [];
            foreach ($plans as $plan) {
                /** @var PlanLimit|null $row */
                $row = ($limits->get($plan->id) ?? collect())->firstWhere('resource', $resource);
                $allowance = $row instanceof PlanLimit ? $row->allowance : $this->usage->systemDefault($resource);
                $values[$plan->id] = $allowance === null ? (string) __('platform_landing.plans.unlimited', [], $locale) : number_format($allowance);
            }
            $limitRows[] = ['label' => (string) __('platform_labels.usage_resource.'.$resource, [], $locale), 'values' => $values];
        }

        return [
            'plans' => $presented,
            'cycles' => [
                'monthly' => collect($presented)->contains(fn (array $plan): bool => $plan['monthly'] !== null),
                'yearly' => collect($presented)->contains(fn (array $plan): bool => $plan['yearly'] !== null),
            ],
            'comparison' => $comparison,
            'limits' => $limitRows,
        ];
    }

    /** @return array{minor: int, formatted: string, per_month: string|null}|null */
    private function price(Plan $plan, string $cycle, string $locale): ?array
    {
        $minor = $plan->priceFor($cycle);
        if ($minor === null) {
            return null;
        }

        return [
            'minor' => $minor,
            'formatted' => $this->currencies->format($minor, $plan->currency, $locale),
            // A yearly price is also shown as its monthly equivalent, rounded
            // down to a whole minor unit so it never overstates the saving.
            'per_month' => $cycle === 'yearly' ? $this->currencies->format(intdiv($minor, 12), $plan->currency, $locale) : null,
        ];
    }
}
