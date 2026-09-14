<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Infrastructure\Jobs;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Exceptions\RegistrationFailed;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\TrialPolicy;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Infrastructure\TenantOperation;
use App\Kernel\Tenancy\TenantProvisioningService;
use App\Modules\Onboarding\Application\CenterBootstrapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a center: database, schema, roles, main branch, owner, trial.
 *
 * Queued because it is slow and failure-prone — creating a database and
 * running migrations inside a web request risks a timeout partway through,
 * leaving a half-built tenant and a user staring at a 504
 * (docs/02-TENANCY.md §8.2).
 *
 * PAYLOAD CONTAINS ONLY A UUID (ADR-028). Not the password, not its hash, not
 * the owner's details. A failed job row can persist indefinitely, so anything
 * in this payload should be assumed permanent.
 *
 * Idempotent throughout: a retry re-enters the same pipeline and must not
 * produce a second tenant, database, branch, owner or subscription.
 */
final class ProvisionRegisteredTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $registrationUuid) {}

    public function handle(
        TenantProvisioningService $provisioner,
        StanclTenantContext $context,
        CenterBootstrapper $bootstrapper,
        TrialPolicy $trials,
        Entitlements $entitlements,
        Audit $audit,
    ): void {
        $registration = Registration::query()->where('uuid', $this->registrationUuid)->first();

        if (! $registration instanceof Registration) {
            return;
        }

        // Already finished — a duplicate dispatch must be a no-op, not a
        // second center.
        if ($registration->status === RegistrationStatus::Ready) {
            return;
        }

        $actor = Actor::system('self-registration');

        try {
            $tenant = $this->tenantFor($registration, $provisioner, $actor);

            $context->runForModel($tenant, function () use ($bootstrapper, $registration): void {
                $bootstrapper->rememberLocale($registration);
                $bootstrapper->bootstrap($registration);
            });

            $subscription = $trials->start($tenant, $this->defaultPlan());

            $entitlements->invalidate($tenant->getTenantKey());

            $registration->forceFill([
                'tenant_id' => $tenant->getTenantKey(),
                'completed_at' => now(),
            ])->save();

            // settle() writes the terminal status AND destroys the bootstrap
            // credential. The control plane must not keep a usable credential a
            // moment longer than provisioning needs one (ADR-031).
            $registration->settle(RegistrationStatus::Ready);

            $audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
                action: 'saas.registration.completed',
                category: AuditCategory::Tenancy,
                actor: $actor,
                targetType: Registration::class,
                targetId: $registration->uuid,
                targetLabel: $registration->center_name,
                after: [
                    'tenant' => $tenant->getTenantKey(),
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                    'plan' => $subscription->plan?->code,
                ],
            ));
        } catch (Throwable $e) {
            $this->fail($registration, $audit, $actor, $e);
        }
    }

    /**
     * Provisions the tenant, or picks up the one a previous attempt created.
     */
    private function tenantFor(
        Registration $registration,
        TenantProvisioningService $provisioner,
        Actor $actor,
    ): TenantModel {
        if ($registration->tenant_id !== null) {
            $existing = TenantModel::query()->find($registration->tenant_id);

            if ($existing instanceof TenantModel) {
                return $existing->toValueObject()->isProvisioned()
                    ? $existing
                    : $this->reprovision($existing, $provisioner, $actor);
            }
        }

        $tenant = $provisioner->provision($registration->center_name, [], $actor);

        // Recorded immediately so a later failure can resume against the same
        // tenant instead of creating another one.
        $registration->forceFill(['tenant_id' => $tenant->id])->save();

        $model = TenantModel::query()->findOrFail($tenant->id);

        $this->assignPublicKey($model);

        return $model;
    }

    private function reprovision(
        TenantModel $tenant,
        TenantProvisioningService $provisioner,
        Actor $actor,
    ): TenantModel {
        $provisioner->retry($tenant, [], $actor);

        $this->assignPublicKey($tenant);

        return $tenant->refresh();
    }

    /**
     * The opaque identifier a client presents at login to say which center it
     * is authenticating against. Random and revocable; the internal sequence is
     * never used for this.
     */
    private function assignPublicKey(TenantModel $tenant): void
    {
        if ($tenant->public_key === null) {
            $tenant->forceFill(['public_key' => 'ctr_'.Str::lower(Str::random(32))])->save();
        }
    }

    private function defaultPlan(): Plan
    {
        $code = PlatformSetting::get(
            PlatformSetting::DEFAULT_PLAN_CODE,
            config('metastyle.saas.default_plan_code'),
        );

        $plan = Plan::query()->where('code', $code)->where('is_active', true)->first();

        return $plan instanceof Plan ? $plan : throw RegistrationFailed::noDefaultPlan();
    }

    /**
     * Records a failure WITHOUT destroying the bootstrap credential.
     *
     * Failure is not terminal: the registration stays retryable until its
     * window closes, and a retry needs the credential to create the owner.
     * Clearing it here — as Phase 3 did — made every failed self-registration
     * permanently unrecoverable, which is not acceptable in production
     * (ADR-031). The sweep destroys it when the window closes.
     */
    private function fail(Registration $registration, Audit $audit, Actor $actor, Throwable $e): void
    {
        $error = TenantOperation::sanitize($e);

        $registration->forceFill([
            'status' => RegistrationStatus::Failed,
            'error' => $error,
        ])->save();

        $audit->record(new AuditEvent(
            action: 'saas.registration.failed',
            category: AuditCategory::Tenancy,
            actor: $actor,
            severity: AuditSeverity::Critical,
            targetType: Registration::class,
            targetId: $registration->uuid,
            targetLabel: $registration->center_name,
            meta: ['error' => $error],
        ));
    }
}
