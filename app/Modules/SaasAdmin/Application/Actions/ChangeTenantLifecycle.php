<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionHistory;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Suspends, resumes, cancels, archives and restores a center.
 *
 * ARCHIVE is how a center is removed. Nothing is deleted: its database, its
 * invoices and payments, its subscription history and every audit entry stay
 * exactly as they were — a hard delete would cascade through SaaS billing
 * history, which this platform must keep. An archived center cannot be used
 * and disappears from the default lists; restoring it brings it back.
 */
final class ChangeTenantLifecycle
{
    /** @var array<string, list<TenantStatus>> */
    private const ALLOWED = [
        'active' => [TenantStatus::Suspended, TenantStatus::Cancelled, TenantStatus::Archived],
        'suspended' => [TenantStatus::Active, TenantStatus::Cancelled, TenantStatus::Archived],
        'cancelled' => [TenantStatus::Active, TenantStatus::Archived],
        'archived' => [TenantStatus::Active],
    ];

    public function __construct(private readonly Audit $audit, private readonly Entitlements $entitlements) {}

    /** @return list<TenantStatus> */
    public static function targetsFrom(string $status): array
    {
        return self::ALLOWED[$status] ?? [];
    }

    public function __invoke(string $tenantId, TenantStatus $target, Actor $actor, string $reason): TenantModel
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('sadmin_centers.errors.reason'));
        }

        [$tenant, $before] = DB::connection('control')->transaction(function () use ($tenantId, $target, $actor, $reason): array {
            /** @var TenantModel|null $tenant */
            $tenant = TenantModel::query()->lockForUpdate()->find($tenantId);
            if (! $tenant instanceof TenantModel) {
                throw new DomainException(__('sadmin_centers.errors.missing'));
            }

            $before = TenantStatus::from((string) $tenant->status);
            if (! in_array($target, self::ALLOWED[$before->value] ?? [], true)) {
                throw new DomainException(__('sadmin_centers.errors.transition'));
            }

            $tenant->forceFill([
                'status' => $target->value,
                'suspended_at' => $target === TenantStatus::Suspended ? now() : null,
                'archived_at' => $target === TenantStatus::Archived ? now() : null,
            ])->save();

            $subscriptionStatus = match ($target) {
                TenantStatus::Active => SubscriptionStatus::Active,
                TenantStatus::Suspended => SubscriptionStatus::Suspended,
                default => SubscriptionStatus::Cancelled,
            };

            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->lockForUpdate()->where('tenant_id', $tenantId)->first();
            if ($subscription instanceof Subscription) {
                $previous = ['plan_id' => $subscription->plan_id, 'cycle' => $subscription->billing_period_snapshot, 'status' => $subscription->status->value];
                // Resuming a center that was still on its trial keeps the trial.
                $keepTrial = $target === TenantStatus::Active && $subscription->trial_ends_at?->isFuture() === true && $subscription->current_period_end?->equalTo($subscription->trial_ends_at) === true;
                $subscription->forceFill([
                    'status' => $keepTrial ? SubscriptionStatus::Trialing : $subscriptionStatus,
                    'cancelled_at' => $subscriptionStatus === SubscriptionStatus::Cancelled ? now() : null,
                ])->save();
                SubscriptionHistory::record($subscription, 'status_changed', $actor, $reason, $previous, ['center_status' => $target->value]);
            }

            DB::connection('control')->table('tenant_lifecycle_history')->insert([
                'tenant_id' => $tenantId, 'from_status' => $before->value, 'to_status' => $target->value, 'reason' => $reason,
                'actor_id' => $actor->id, 'actor_label' => $actor->label, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$tenant, $before];
        });

        $this->entitlements->invalidate($tenantId);

        $this->audit->recordForTenant($tenantId, new AuditEvent(
            action: $target === TenantStatus::Archived ? 'platform.center.archived' : ($before === TenantStatus::Archived ? 'platform.center.restored' : 'platform.center.lifecycle.changed'),
            category: AuditCategory::Tenancy,
            actor: $actor,
            severity: in_array($target, [TenantStatus::Archived, TenantStatus::Cancelled], true) ? AuditSeverity::Warning : AuditSeverity::Notice,
            targetType: TenantModel::class,
            targetId: $tenantId,
            targetLabel: $tenant->name,
            before: ['status' => $before->value],
            after: ['status' => $target->value],
            reason: $reason,
        ));

        return $tenant;
    }
}
