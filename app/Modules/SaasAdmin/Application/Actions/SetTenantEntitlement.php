<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use DomainException;
use Illuminate\Support\Carbon;

final class SetTenantEntitlement
{
    public function __construct(private readonly EntitlementCatalog $catalog, private readonly Entitlements $entitlements, private readonly Audit $audit) {}

    public function __invoke(string $tenantId, string $key, OverrideMode $mode, Actor $actor, string $reason, ?Carbon $expiresAt = null): TenantEntitlementOverride
    {
        $this->catalog->assertKnown($key);
        if (trim($reason) === '' || ($expiresAt !== null && $expiresAt->isPast())) {
            throw new DomainException('A reason and a future expiry, when supplied, are required.');
        }
        /** @var TenantEntitlementOverride $override */
        $override = TenantEntitlementOverride::query()->updateOrCreate(['tenant_id' => $tenantId, 'entitlement' => $key], ['mode' => $mode->value, 'source' => 'platform', 'reason' => trim($reason), 'starts_at' => now(), 'expires_at' => $expiresAt]);
        $this->entitlements->invalidate($tenantId);
        $this->audit->recordForTenant($tenantId, new AuditEvent(action: 'platform.entitlement.override.set', category: AuditCategory::Config, actor: $actor, targetType: TenantEntitlementOverride::class, targetId: (string) $override->id, targetLabel: $key, after: ['mode' => $mode->value, 'expires_at' => $expiresAt?->toIso8601String()], reason: trim($reason)));

        return $override;
    }

    public function clear(string $tenantId, string $key, Actor $actor, string $reason): void
    {
        $this->catalog->assertKnown($key);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A reason is required to reset an entitlement override.');
        }

        /** @var TenantEntitlementOverride|null $override */
        $override = TenantEntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('entitlement', $key)
            ->first();

        if (! $override instanceof TenantEntitlementOverride) {
            return;
        }

        $before = ['mode' => $override->mode->value, 'expires_at' => $override->expires_at?->toIso8601String()];
        $targetId = (string) $override->id;
        $override->delete();
        $this->entitlements->invalidate($tenantId);
        $this->audit->recordForTenant($tenantId, new AuditEvent(
            action: 'platform.entitlement.override.cleared',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: TenantEntitlementOverride::class,
            targetId: $targetId,
            targetLabel: $key,
            before: $before,
            reason: $reason,
        ));
    }
}
