<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Answers "does this tenant own this capability, and may they use it now".
 *
 * Business code asks for a CAPABILITY, never for a plan name. `if ($plan ===
 * 'pro')` is banned, because plans get renamed, split, merged, discounted and
 * grandfathered, and every one of those becomes a code change once a plan name
 * is in business logic (docs/05-ENTITLEMENTS.md §1).
 *
 * Resolution, in order:
 *
 *     plan grants
 *       → tenant overrides   (grant adds, revoke removes — revoke wins)
 *       → dependency closure (drop anything whose requirements are unmet)
 *       → access clamp       (an expired trial or suspension disables use,
 *                             without changing what the tenant OWNS)
 *
 * Ownership and usability are kept apart on purpose: a suspended tenant on the
 * Enterprise plan still owns every capability, which is what makes
 * reinstatement a status change rather than a re-purchase.
 */
final class Entitlements
{
    /** @var array<string, EffectiveEntitlements> */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementCatalog $catalog,
        private readonly Cache $cache,
    ) {}

    /**
     * Does the current tenant own AND currently have use of the capability?
     */
    public function enabled(string $key): bool
    {
        return $this->for($this->tenants->require()->id)->enabled($key);
    }

    /**
     * Ownership, ignoring subscription status.
     *
     * For billing and upgrade surfaces — never for gating an action.
     */
    public function owns(string $key): bool
    {
        return $this->for($this->tenants->require()->id)->owns($key);
    }

    /**
     * The authoritative gate.
     *
     * Call this inside Actions, not only in route middleware: WhatsApp
     * webhooks, RAYAN tool calls, queued jobs and console commands never pass
     * through HTTP middleware, and they are exactly the paths that will exist
     * by Phase 13 (docs/05-ENTITLEMENTS.md §6.2).
     *
     * @throws EntitlementRequired
     */
    public function ensure(string $key): void
    {
        $this->catalog->assertKnown($key);

        if (! $this->enabled($key)) {
            throw new EntitlementRequired($key);
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->for($this->tenants->require()->id)->usable();
    }

    public function accessLevel(): TenantAccessLevel
    {
        return $this->for($this->tenants->require()->id)->accessLevel;
    }

    /**
     * Resolves for a specific tenant, with or without tenant context bound.
     *
     * Used by console commands and by the provisioning pipeline, both of which
     * run in platform mode.
     */
    public function for(string $tenantId): EffectiveEntitlements
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $version = $this->version($tenantId);
        $key = "entitlements:{$tenantId}:v{$version}";

        /** @var array{owned: list<string>, access: string} $cached */
        $cached = $this->cache->remember($key, now()->addHour(), function () use ($tenantId): array {
            $resolved = $this->resolve($tenantId);

            return ['owned' => $resolved->owned, 'access' => $resolved->accessLevel->value];
        });

        return $this->memo[$tenantId] = new EffectiveEntitlements(
            $cached['owned'],
            TenantAccessLevel::from($cached['access']),
        );
    }

    /**
     * Drops cached resolution for a tenant.
     *
     * Bumping the version is the invalidation: cache keys embed it, so stale
     * entries simply become unreachable and expire on their own. No tag sweep,
     * no fan-out, nothing to get wrong under concurrency
     * (docs/05-ENTITLEMENTS.md §5.2).
     */
    public function invalidate(string $tenantId): void
    {
        TenantModel::query()->whereKey($tenantId)->increment('entitlements_version');

        unset($this->memo[$tenantId]);
    }

    private function version(string $tenantId): int
    {
        $version = TenantModel::query()->whereKey($tenantId)->value('entitlements_version');

        return is_numeric($version) ? (int) $version : 1;
    }

    private function resolve(string $tenantId): EffectiveEntitlements
    {
        $subscription = Subscription::query()
            ->with('plan.entitlements')
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $subscription instanceof Subscription) {
            // No subscription means nothing is owned. Failing closed here is
            // deliberate: a missing subscription is a data problem, and
            // guessing "probably everything" would hand out the product.
            return new EffectiveEntitlements([], TenantAccessLevel::Blocked);
        }

        $granted = $subscription->plan?->entitlementCodes() ?? [];

        foreach ($this->overrides($tenantId) as $override) {
            $granted = $override->mode === OverrideMode::Grant
                ? array_merge($granted, [$override->entitlement])
                : array_values(array_diff($granted, [$override->entitlement]));
        }

        $owned = $this->catalog->applyDependencies(array_values(array_unique($granted)));

        return new EffectiveEntitlements(
            $owned,
            TenantAccessLevel::fromSubscription($subscription->effectiveStatus()),
        );
    }

    /**
     * @return list<TenantEntitlementOverride>
     */
    private function overrides(string $tenantId): array
    {
        /** @var list<TenantEntitlementOverride> $overrides */
        $overrides = TenantEntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->active()
            // Revocations last, so a revoke always beats a grant for the same
            // key regardless of insertion order.
            ->orderByRaw("CASE WHEN mode = 'revoke' THEN 1 ELSE 0 END")
            ->get()
            ->all();

        return $overrides;
    }
}
