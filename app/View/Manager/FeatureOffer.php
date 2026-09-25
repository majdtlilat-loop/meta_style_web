<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\TenantAccessLevel;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\CurrentSubscription;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\PlanOffers;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Allowances;
use App\View\Label;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * What the Manager says about a feature the center does not own.
 *
 * Presentation only. Everything is read from data — the entitlement catalog,
 * the center's subscription, the public plans and their prices in the plan's
 * own currency — never from a plan name in code, and never a claim the
 * catalog cannot back ("recommended" only when a plan is configured featured;
 * copy only from lang/manager_features for features the Manager shows).
 *
 * It never grants anything: a locked page stays locked on the server, however
 * this offer is rendered.
 *
 * Memoised per instance. The sidebar asks for a lock LABEL per locked item,
 * which needs only the plan catalog (one query, shared); the full offer —
 * prices, allowances — is built only for the one feature a person opens.
 */
final class FeatureOffer
{
    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    /** @var array<string, array{state: 'upgrade'|'contact'|'unavailable', from: Plan|null, current_plan_id: int|null}> */
    private array $positions = [];

    /** @var array<string, int|null> */
    private array $currentAllowances = [];

    public function __construct(
        private readonly EntitlementCatalog $catalog,
        private readonly Entitlements $entitlements,
        private readonly PlanOffers $offers,
        private readonly CurrentSubscription $subscription,
        private readonly PlatformCurrencies $currencies,
        private readonly Allowances $allowances,
        private readonly TenantContext $tenants,
    ) {}

    /** Whether the bound center owns the feature (its plan, overrides and dependencies). */
    public function owns(string $key): bool
    {
        return $this->catalog->has($key) && $this->entitlements->owns($key);
    }

    /**
     * Whether a lock should be SHOWN for the feature. Not when it is owned,
     * and not when the subscription is suspended, cancelled or expired — that
     * is one account-wide banner, not "your plan lacks everything".
     */
    public function isLocked(string $key): bool
    {
        if ($this->owns($key)) {
            return false;
        }

        return $this->entitlements->accessLevel() === TenantAccessLevel::Full;
    }

    /**
     * The short, secondary label shown next to a locked item: "Available from
     * Business", or "Contact us" when no public plan includes it.
     */
    public function lockLabel(string $key, ?string $locale = null): ?string
    {
        if (! $this->catalog->has($key)) {
            return null;
        }

        return $this->labelFor($this->position($key), $locale ?? app()->getLocale());
    }

    /** 'upgrade' | 'contact' | 'unavailable' — see {@see self::for()}. */
    public function state(string $key): ?string
    {
        return $this->catalog->has($key) ? $this->position($key)['state'] : null;
    }

    /**
     * The whole offer for one feature.
     *
     * @return array{
     *     key: string,
     *     name: string,
     *     summary: string|null,
     *     capabilities: list<string>,
     *     requires: list<string>,
     *     owned: bool,
     *     state: 'upgrade'|'contact'|'unavailable',
     *     lock_label: string,
     *     available_from: string|null,
     *     current: array{name: string, status: string, status_label: string, cycle_label: string|null, price: string|null}|null,
     *     plans: list<array{uuid: string, name: string, featured: bool, current: bool, monthly: string|null, yearly: string|null, saving: int|null, limits: list<array{label: string, value: string}>}>,
     *     links: array{plans: string|null, support: string|null}
     * }|null
     */
    public function for(string $key, ?string $locale = null, ?User $viewer = null): ?array
    {
        if (! $this->catalog->has($key)) {
            return null;
        }

        $locale ??= app()->getLocale();
        $memoKey = $key.'|'.$locale.'|'.($viewer?->getKey() ?? '-');
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $current = $this->subscription->summary();
        $position = $this->position($key);
        $currentPlanId = $position['current_plan_id'];
        $from = $position['from'];

        $name = __('platform_labels.entitlement.'.$key);
        $capabilities = Lang::has('manager_features.'.$key.'.capabilities')
            ? array_values(array_filter((array) __('manager_features.'.$key.'.capabilities'), 'is_string'))
            : [];

        return $this->memo[$memoKey] = [
            'key' => $key,
            'name' => $name,
            'summary' => Lang::has('manager_features.'.$key.'.summary') ? __('manager_features.'.$key.'.summary') : null,
            'capabilities' => $capabilities,
            'requires' => array_values(array_map(
                static fn (string $dependency): string => __('platform_labels.entitlement.'.$dependency),
                array_filter($this->catalog->requires($key), fn (string $dependency): bool => ! $this->owns($dependency)),
            )),
            'owned' => $this->owns($key),
            'state' => $position['state'],
            'lock_label' => $this->labelFor($position, $locale),
            'available_from' => $from?->name->get($locale),
            'current' => $current === null ? null : [
                'name' => $current->planName($locale),
                'status' => $current->status->value,
                'status_label' => Label::for('subscription_status', $current->status->value),
                'cycle_label' => $current->cycle !== null ? Label::for('billing_period', $current->cycle) : null,
                'price' => $current->priceMinor !== null && $current->currency !== null
                    ? $this->currencies->format($current->priceMinor, $current->currency, $locale)
                    : null,
            ],
            'plans' => array_map(fn (Plan $plan): array => $this->plan($plan, $locale, $currentPlanId), $this->offers->including($key)),
            'links' => [
                // Straight to the comparison, with the feature highlighted —
                // for somebody who may open the Plan page (`settings.view`);
                // anyone else would land on a refusal.
                'plans' => Route::has('center.plan') && ($viewer === null || $viewer->hasPermission(Permission::SettingsView))
                    ? route('center.plan', ['feature' => $key]).'#compare'
                    : null,
                'support' => $this->supportLink($viewer, $name),
            ],
        ];
    }

    /**
     * Platform support, for someone who may read it; with the request already
     * titled for someone who may open one.
     */
    private function supportLink(?User $viewer, string $feature): ?string
    {
        if (! Route::has('center.support') || ($viewer !== null && ! $viewer->hasPermission(Permission::PlatformSupportView))) {
            return null;
        }

        return $viewer !== null && $viewer->hasPermission(Permission::PlatformSupportManage)
            ? route('center.support', ['subject' => __('manager_shell.upgrade.support_subject', ['feature' => $feature])])
            : route('center.support');
    }

    /**
     * Where the center stands for one feature, from the catalog alone.
     *
     * @return array{state: 'upgrade'|'contact'|'unavailable', from: Plan|null, current_plan_id: int|null}
     */
    private function position(string $key): array
    {
        if (isset($this->positions[$key])) {
            return $this->positions[$key];
        }

        $current = $this->subscription->summary();
        $currentPlanId = $current?->plan !== null ? (int) $current->plan->getKey() : null;
        $from = $this->offers->lowestIncluding($key, $currentPlanId);
        $currentIncludes = $current?->plan !== null
            && in_array($key, $this->offers->effectiveCodes($current->plan), true);

        return $this->positions[$key] = [
            'state' => match (true) {
                $currentIncludes => 'unavailable',
                $from !== null => 'upgrade',
                default => 'contact',
            },
            'from' => $from,
            'current_plan_id' => $currentPlanId,
        ];
    }

    /** @param array{state: 'upgrade'|'contact'|'unavailable', from: Plan|null, current_plan_id: int|null} $position */
    private function labelFor(array $position, string $locale): string
    {
        return match (true) {
            $position['state'] === 'upgrade' && $position['from'] !== null => __('manager_features.lock.available_from', ['plan' => $position['from']->name->get($locale)]),
            $position['state'] === 'unavailable' => __('manager_features.lock.not_available'),
            default => __('manager_features.lock.contact'),
        };
    }

    /**
     * @return array{uuid: string, name: string, featured: bool, current: bool, monthly: string|null, yearly: string|null, saving: int|null, limits: list<array{label: string, value: string}>}
     */
    private function plan(Plan $plan, string $locale, ?int $currentPlanId): array
    {
        $monthly = $plan->priceFor('monthly');
        $yearly = $plan->priceFor('yearly');

        return [
            'uuid' => (string) $plan->uuid,
            'name' => $plan->name->get($locale),
            'featured' => $plan->is_featured,
            'current' => $currentPlanId !== null && (int) $plan->getKey() === $currentPlanId,
            'monthly' => $monthly !== null ? $this->currencies->format($monthly, (string) $plan->currency, $locale) : null,
            'yearly' => $yearly !== null ? $this->currencies->format($yearly, (string) $plan->currency, $locale) : null,
            'saving' => $plan->yearlySavingPercent(),
            'limits' => $this->limits($plan),
        ];
    }

    /**
     * Enforced allowances where the plan differs from what the center has now.
     *
     * @return list<array{label: string, value: string}>
     */
    private function limits(Plan $plan): array
    {
        $tenantId = $this->tenants->id();
        $rows = [];
        foreach ($this->offers->limits($plan) as $resource => $allowance) {
            if ($tenantId !== null && $this->currentAllowance($tenantId, $resource) === $allowance) {
                continue;
            }
            $rows[] = [
                'label' => Lang::has('usage.resource_'.$resource) ? __('usage.resource_'.$resource) : $resource,
                'value' => $allowance === null ? __('usage.unlimited') : number_format($allowance),
            ];
        }

        return $rows;
    }

    /** What the center is allowed now (null = unlimited), once per resource. */
    private function currentAllowance(string $tenantId, string $resource): ?int
    {
        if (! array_key_exists($resource, $this->currentAllowances)) {
            $this->currentAllowances[$resource] = $this->allowances->resolve($tenantId, $resource)->allowance;
        }

        return $this->currentAllowances[$resource];
    }
}
