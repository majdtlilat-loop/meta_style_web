<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\CurrentSubscription;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\PlanOffers;
use App\Kernel\SaaS\SubscriptionSummary;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Allowances;
use App\Kernel\Usage\UsageCatalog;
use App\View\Label;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * The Plan page, read-only: what the center is subscribed to, what that
 * gives it, and how the public plans compare.
 *
 * Every value is data — the subscription's own snapshot (name, price, cycle,
 * currency as SOLD), the entitlement catalog, the public plans in commercial
 * order with their prices in each plan's own currency. "Recommended" appears
 * only on a plan configured featured. Nothing here changes a subscription;
 * plan changes are Meta Style's (Super Admin), so the only action offered is
 * to ask, through platform support.
 */
final class PlanOverview
{
    public function __construct(
        private readonly CurrentSubscription $subscription,
        private readonly PlanOffers $offers,
        private readonly EntitlementCatalog $catalog,
        private readonly Entitlements $entitlements,
        private readonly PlatformCurrencies $currencies,
        private readonly Allowances $allowances,
        private readonly UsageCatalog $usage,
        private readonly TenantContext $tenants,
        private readonly ViewerTimezone $timezones,
    ) {}

    /**
     * @return array{
     *     summary: array<string, mixed>|null,
     *     features: list<array{category: string, items: list<array{key: string, name: string, added: bool}>}>,
     *     allowances: list<array{label: string, value: string, source: string}>,
     *     compare: array<string, mixed>,
     *     links: array{usage: string|null, support: string|null}
     * }
     */
    public function build(User $viewer, string $cycle, string $highlight, string $locale): array
    {
        $timezone = $this->timezones->for($viewer);
        $summary = $this->subscription->summary();
        $planCodes = $summary?->plan !== null ? $this->offers->effectiveCodes($summary->plan) : [];

        return [
            'summary' => $summary === null ? null : $this->summary($summary, $locale, $timezone),
            'features' => $this->features($planCodes),
            'allowances' => $this->allowanceRows(),
            'compare' => $this->compare($summary, $cycle, $highlight, $locale, $viewer),
            'links' => [
                'usage' => Route::has('center.usage') ? route('center.usage') : null,
                'support' => $this->supportLink($viewer, null),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(SubscriptionSummary $summary, string $locale, string $timezone): array
    {
        $status = $summary->status;

        return [
            'name' => $summary->planName($locale),
            'description' => $summary->plan?->description?->get($locale) ?: null,
            'status' => $status->value,
            'status_label' => Label::for('subscription_status', $status->value),
            'cycle_label' => $summary->cycle !== null ? Label::for('billing_period', $summary->cycle) : null,
            'price' => $summary->priceMinor !== null && $summary->currency !== null
                ? $this->currencies->format($summary->priceMinor, $summary->currency, $locale)
                : null,
            'free' => $summary->priceMinor === 0,
            'trial' => $status === SubscriptionStatus::Trialing,
            'trial_ends' => $this->date($summary->trialEndsAt, $locale, $timezone),
            'trial_days_left' => $summary->trialDaysLeft,
            'renews' => in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) ? $this->date($summary->renewsAt, $locale, $timezone) : null,
            'period_ends' => ! in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Trialing], true) ? $this->date($summary->renewsAt, $locale, $timezone) : null,
            'grace_ends' => $status === SubscriptionStatus::PastDue ? $this->date($summary->graceEndsAt, $locale, $timezone) : null,
            'scheduled' => $summary->scheduledPlan !== null ? [
                'plan' => $summary->scheduledPlan->name->get($locale),
                'at' => $this->date($summary->scheduledAt, $locale, $timezone),
            ] : null,
            'read_only' => ! $summary->accessLevel->allowsWrites(),
        ];
    }

    /**
     * What the center owns, grouped by category. "Added for your center"
     * marks what an override grants beyond the plan.
     *
     * @param  list<string>  $planCodes
     * @return list<array{category: string, items: list<array{key: string, name: string, added: bool}>}>
     */
    private function features(array $planCodes): array
    {
        $groups = [];
        foreach ($this->catalog->keys() as $key) {
            if (! $this->entitlements->owns($key)) {
                continue;
            }
            $category = $this->catalog->category($key);
            $groups[$category][] = [
                'key' => $key,
                'name' => (string) __('platform_labels.entitlement.'.$key),
                'added' => ! in_array($key, $planCodes, true),
            ];
        }

        $out = [];
        foreach ($groups as $category => $items) {
            $out[] = ['category' => $this->categoryLabel($category), 'items' => $items];
        }

        return $out;
    }

    /** @return list<array{label: string, value: string, source: string}> */
    private function allowanceRows(): array
    {
        $tenantId = $this->tenants->id();
        if ($tenantId === null) {
            return [];
        }

        $rows = [];
        foreach ($this->usage->codes() as $resource) {
            if (! $this->usage->isEnforced($resource)) {
                continue;
            }
            $resolved = $this->allowances->resolve($tenantId, $resource);
            $rows[] = [
                'label' => $this->resourceLabel($resource),
                'value' => $resolved->allowance === null ? (string) __('usage.unlimited') : number_format($resolved->allowance),
                'source' => (string) __('manager_plan.allowances.source_'.$resolved->source),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function compare(?SubscriptionSummary $summary, string $cycle, string $highlight, string $locale, User $viewer): array
    {
        $current = $summary?->plan;
        $currentId = $current !== null ? (int) $current->getKey() : null;
        $public = $this->offers->all();

        $plans = $public;
        $currentIsPublic = $currentId !== null && array_filter($public, static fn (Plan $plan): bool => (int) $plan->getKey() === $currentId) !== [];
        if ($current !== null && ! $currentIsPublic) {
            // A custom or trial plan is shown for comparison, never offered.
            array_unshift($plans, $current);
        }

        $columns = array_map(fn (Plan $plan): array => $this->column($plan, $cycle, $locale, $currentId, $viewer), $plans);

        $included = [];
        foreach ($plans as $plan) {
            foreach ($this->offers->effectiveCodes($plan) as $code) {
                $included[$code] = true;
            }
        }
        $highlight = $this->catalog->has($highlight) ? $highlight : '';

        $groups = [];
        foreach ($this->catalog->keys() as $key) {
            if (! isset($included[$key]) && $key !== $highlight) {
                continue;
            }
            $groups[$this->catalog->category($key)][] = [
                'key' => $key,
                'name' => (string) __('platform_labels.entitlement.'.$key),
                'highlight' => $key === $highlight,
                'cells' => array_map(fn (Plan $plan): bool => in_array($key, $this->offers->effectiveCodes($plan), true), $plans),
            ];
        }

        $featureRows = [];
        foreach ($groups as $category => $rows) {
            $featureRows[] = ['category' => $this->categoryLabel($category), 'rows' => $rows];
        }

        $limitRows = [];
        foreach ($this->usage->codes() as $resource) {
            if (! $this->usage->isEnforced($resource)) {
                continue;
            }
            $limitRows[] = [
                'label' => $this->resourceLabel($resource),
                'cells' => array_map(function (Plan $plan) use ($resource): string {
                    $allowance = $this->offers->limits($plan)[$resource] ?? null;

                    return $allowance === null ? (string) __('usage.unlimited') : number_format($allowance);
                }, $plans),
            ];
        }

        return [
            'cycle' => $cycle,
            'columns' => $columns,
            'features' => $featureRows,
            'limits' => $limitRows,
            'highlight' => $highlight === '' ? null : [
                'key' => $highlight,
                'name' => (string) __('platform_labels.entitlement.'.$highlight),
                'owned' => $this->entitlements->owns($highlight),
                'offered' => $this->offers->including($highlight) !== [],
            ],
            'has_yearly' => array_filter($plans, static fn (Plan $plan): bool => $plan->offers('yearly')) !== [],
        ];
    }

    /** @return array<string, mixed> */
    private function column(Plan $plan, string $cycle, string $locale, ?int $currentId, User $viewer): array
    {
        $price = $plan->priceFor($cycle);
        $current = $currentId !== null && (int) $plan->getKey() === $currentId;
        $name = $plan->name->get($locale);

        return [
            'uuid' => (string) $plan->uuid,
            'name' => $name,
            'current' => $current,
            'public' => $plan->is_public,
            'featured' => $plan->is_featured && $plan->is_public,
            'price' => $price !== null ? $this->currencies->format($price, (string) $plan->currency, $locale) : null,
            'per' => $cycle === 'yearly' ? (string) __('manager_plan.compare.per_year') : (string) __('manager_plan.compare.per_month'),
            'saving' => $cycle === 'yearly' ? $plan->yearlySavingPercent() : null,
            'request' => $current || ! $plan->is_public ? null : $this->supportLink($viewer, (string) __('manager_plan.compare.request_subject', ['plan' => $name])),
        ];
    }

    private function supportLink(User $viewer, ?string $subject): ?string
    {
        if (! Route::has('center.support') || ! $viewer->hasPermission(Permission::PlatformSupportView)) {
            return null;
        }

        // Only somebody who may open a ticket gets a prefilled one.
        return $subject !== null && $viewer->hasPermission(Permission::PlatformSupportManage)
            ? route('center.support', ['subject' => $subject])
            : route('center.support');
    }

    private function categoryLabel(string $category): string
    {
        return Lang::has('platform_labels.entitlement_category.'.$category)
            ? (string) __('platform_labels.entitlement_category.'.$category)
            : (string) __('manager_plan.features.other');
    }

    private function resourceLabel(string $resource): string
    {
        return Lang::has('usage.resource_'.$resource) ? (string) __('usage.resource_'.$resource) : $resource;
    }

    private function date(?CarbonInterface $instant, string $locale, string $timezone): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->setTimezone($timezone)->locale($locale)->translatedFormat('j F Y');
    }
}
