<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Usage\Models\TenantLimitOverride;
use App\Kernel\Usage\UsageCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Sets, changes or clears one center's allowance for one resource.
 *
 * The ONLY writer of `tenant_limit_overrides`, and the place the version stamp
 * is maintained — a write that bumped the allowance without bumping the version
 * would be invisible to reconciliation and would simply never reach the center
 * (docs/26-USAGE-QUOTAS.md §8).
 *
 * ## `enforce_immediately` is audited as a separate, louder thing
 *
 * Lowering a center's allowance mid-period is a real intervention: it can stop
 * a feature the center is using right now, in front of their own customers.
 * So it is flagged on the row, requires a reason, and is recorded at `Warning`
 * severity rather than `Info` — the audit trail has to make it easy to find
 * every time somebody did this, and why (§6).
 *
 * Ordinary changes need no such treatment, because an increase takes effect at
 * once and a decrease waits for the next period. Neither can surprise a center
 * mid-month.
 */
final class SetTenantLimitOverride
{
    public function __construct(
        private readonly UsageCatalog $catalog,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  int|null  $allowance  null means UNLIMITED — never "unset". Use
     *                               {@see clear()} to fall back to the plan.
     */
    public function __invoke(
        string $tenantId,
        string $resource,
        ?int $allowance,
        Actor $actor,
        string $reason,
        bool $enforceImmediately = false,
    ): TenantLimitOverride {
        $this->catalog->assertKnown($resource);

        $override = DB::connection('control')->transaction(
            function () use ($tenantId, $resource, $allowance, $actor, $reason, $enforceImmediately): TenantLimitOverride {
                /** @var TenantLimitOverride|null $existing */
                $existing = TenantLimitOverride::query()
                    ->where('tenant_id', $tenantId)
                    ->where('resource', $resource)
                    ->lockForUpdate()
                    ->first();

                $values = [
                    'allowance' => $allowance,
                    'enforce_immediately' => $enforceImmediately,
                    'reason' => $reason,
                    'set_by_id' => $actor->id,
                    'set_by_label' => $actor->label,
                    /*
                     * Bumped on EVERY write, including one that sets the same
                     * number again. The version answers "is the tenant's copy
                     * from this state of this row", and re-affirming an
                     * allowance is a new state — a reconciler that skipped it
                     * would leave a center whose earlier sync failed still
                     * stale (§8).
                     */
                    'version' => ($existing->version ?? 0) + 1,
                ];

                if ($existing instanceof TenantLimitOverride) {
                    $existing->forceFill($values)->save();

                    return $existing;
                }

                /** @var TenantLimitOverride $created */
                $created = TenantLimitOverride::query()->create(
                    $values + ['tenant_id' => $tenantId, 'resource' => $resource]
                );

                return $created;
            }
        );

        $this->record($tenantId, $resource, $override, $actor, $reason, $enforceImmediately);

        return $override;
    }

    /**
     * Removes the override, so the plan's allowance applies again.
     *
     * Not the same as setting it to null — null is a deliberate grant of
     * unlimited, and the two must never be confused (§4).
     */
    public function clear(string $tenantId, string $resource, Actor $actor, string $reason): void
    {
        $this->catalog->assertKnown($resource);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('A reason is required to reset a usage override.');
        }

        $deleted = TenantLimitOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('resource', $resource)
            ->delete();

        if ($deleted === 0) {
            return;
        }

        $this->audit->record(new AuditEvent(
            action: 'usage.limit_override.cleared',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: TenantLimitOverride::class,
            targetId: $tenantId,
            targetLabel: $resource,
            reason: $reason,
        ));
    }

    private function record(
        string $tenantId,
        string $resource,
        TenantLimitOverride $override,
        Actor $actor,
        string $reason,
        bool $enforceImmediately,
    ): void {
        $this->audit->record(new AuditEvent(
            action: 'usage.limit_override.set',
            category: AuditCategory::Config,
            actor: $actor,
            severity: $enforceImmediately ? AuditSeverity::Warning : AuditSeverity::Info,
            targetType: TenantLimitOverride::class,
            targetId: $tenantId,
            targetLabel: $resource,
            after: [
                'allowance' => $override->allowance,
                'version' => $override->version,
                'enforce_immediately' => $enforceImmediately,
            ],
            meta: ['resource' => $resource, 'unlimited' => $override->allowance === null],
            reason: $reason,
        ));
    }
}
