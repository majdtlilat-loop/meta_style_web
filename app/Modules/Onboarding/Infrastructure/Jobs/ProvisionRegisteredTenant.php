<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Infrastructure\Jobs;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Actions\IssueCenterPasswordLink;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Directory\CenterUserDirectory;
use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Exceptions\RegistrationFailed;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionHistory;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\SaaS\TrialPolicy;
use App\Kernel\Tenancy\Infrastructure\DomainModel;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Infrastructure\TenantOperation;
use App\Kernel\Tenancy\PlatformHosts;
use App\Kernel\Tenancy\TenantProvisioningService;
use App\Modules\Onboarding\Application\CenterBootstrapper;
use App\Modules\Onboarding\Mail\CenterCreatedEmail;
use App\Modules\Onboarding\Mail\CenterOwnerInvitationEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
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
        PlatformHosts $hosts,
        PlatformNotifier $notifier,
        IssueCenterPasswordLink $links,
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

        // A forged/duplicate queue payload must never bypass email ownership.
        if ($registration->email_verified_at === null) {
            return;
        }

        // The history names who asked for the center: its owner, or the platform.
        $actor = Actor::system($registration->source === 'platform' ? 'center-created-by-platform' : 'self-registration');

        try {
            $tenant = $this->tenantFor($registration, $provisioner, $actor);

            $context->runForModel($tenant, function () use ($bootstrapper, $registration): void {
                $bootstrapper->rememberLocale($registration);
                $bootstrapper->bootstrap($registration);
            });

            $plan = $this->selectedPlan($registration);
            $this->applyCenterProfile($registration, $tenant);
            $subscription = $trials->start($tenant, $plan);
            $subscription = $this->applyCommercialChoices($registration, $subscription, $plan);
            SubscriptionHistory::record($subscription, 'created', $actor, null);
            $this->applyFeatureChoices($registration, $tenant, $audit);

            $entitlements->invalidate($tenant->getTenantKey());

            $registration->forceFill([
                'tenant_id' => $tenant->getTenantKey(),
                'completed_at' => now(),
            ])->save();

            // settle() writes the terminal status AND destroys the bootstrap
            // credential. The control plane must not keep a usable credential a
            // moment longer than provisioning needs one (ADR-031).
            $registration->settle(RegistrationStatus::Ready);

            // The new owner appears in the Super Admin's Center Users directory
            // right away. A read model: it may never fail a provisioning.
            try {
                app(CenterUserDirectory::class)->refreshTenant($tenant);
            } catch (Throwable) {
                // The scheduled projection catches up.
            }

            if ($registration->source === 'platform') {
                $this->sendOwnerInvitation($registration, $tenant, $plan, $hosts, $context, $links);
            } else {
                $this->sendConfirmation($registration, $tenant, $plan, $hosts);
            }
            $notifier->notify('centers.registered', 'info', $registration->source === 'platform' ? 'center_created' : 'center_registered', ['center' => $registration->center_name, 'plan' => $plan->name->get('en')], $tenant->getTenantKey(), '/centers/'.$tenant->getTenantKey());

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
                    'plan' => $plan->code,
                ],
            ));
        } catch (Throwable $e) {
            $this->fail($registration, $audit, $actor, $e);
            $notifier->notify('centers.provisioning_failed', 'critical', 'provisioning_failed', ['center' => $registration->center_name], $registration->tenant_id, '/centers?view=provisioning');
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
                    : $this->reprovision($existing, $registration, $provisioner, $actor);
            }
        }

        // The requested slug names the database (ADR-106); the display name,
        // which may be Arabic or Kurdish, never does.
        $tenant = $provisioner->provision($registration->center_name, [], $actor, $registration->requested_slug);

        // Recorded immediately so a later failure can resume against the same
        // tenant instead of creating another one.
        $registration->forceFill(['tenant_id' => $tenant->id])->save();

        $model = TenantModel::query()->findOrFail($tenant->id);

        $this->assignPublicIdentity($model, $registration);

        return $model;
    }

    private function reprovision(
        TenantModel $tenant,
        Registration $registration,
        TenantProvisioningService $provisioner,
        Actor $actor,
    ): TenantModel {
        $provisioner->retry($tenant, [], $actor);

        $this->assignPublicIdentity($tenant, $registration);

        return $tenant->refresh();
    }

    /**
     * The opaque identifier a client presents at login to say which center it
     * is authenticating against. Random and revocable; the internal sequence is
     * never used for this.
     */
    private function assignPublicIdentity(TenantModel $tenant, Registration $registration): void
    {
        $hosts = app(PlatformHosts::class);
        $slug = (string) $registration->requested_slug;

        if (! $hosts->isValidCenterSlug($slug)) {
            throw new \RuntimeException('Registration has no valid center subdomain.');
        }

        $tenant->forceFill([
            'public_key' => $tenant->public_key ?? 'ctr_'.Str::lower(Str::random(32)),
            'slug' => $tenant->slug ?? $slug,
        ])->save();

        DomainModel::query()->updateOrCreate(
            ['domain' => $hosts->centerHost($slug)],
            ['tenant_id' => $tenant->getTenantKey(), 'is_primary' => true],
        );
    }

    private function selectedPlan(Registration $registration): Plan
    {
        if (is_numeric($registration->selected_plan_id)) {
            $selected = Plan::query()->whereKey((int) $registration->selected_plan_id)->where('is_active', true)->first();

            if ($selected instanceof Plan) {
                return $selected;
            }
        }

        $code = PlatformSetting::get(
            PlatformSetting::DEFAULT_PLAN_CODE,
            config('metastyle.saas.default_plan_code'),
        );

        $plan = Plan::query()->where('code', $code)->where('is_active', true)->first();

        return $plan instanceof Plan ? $plan : throw RegistrationFailed::noDefaultPlan();
    }

    /**
     * Contact details on the control-plane record, so the Super Admin sees
     * who owns the center without opening its database. A Super Admin's
     * choice of currency and timezone is applied here too.
     */
    private function applyCenterProfile(Registration $registration, TenantModel $tenant): void
    {
        $options = $registration->options ?? [];
        $tenant->forceFill([
            'contact_name' => $tenant->contact_name ?? $registration->owner_name,
            'contact_email' => $tenant->contact_email ?? $registration->owner_email,
            'contact_phone' => $tenant->contact_phone ?? $registration->owner_phone,
            'currency' => $tenant->currency ?? (is_string($options['currency'] ?? null) ? $options['currency'] : null),
            'timezone' => $tenant->timezone ?? (is_string($options['timezone'] ?? null) ? $options['timezone'] : null),
            'trial_days_override' => $tenant->trial_days_override ?? (is_int($options['trial_days'] ?? null) && $options['trial_days'] > 0 ? $options['trial_days'] : null),
        ])->save();
    }

    /**
     * The billing cycle a Super Admin picked, and whether the center starts on
     * a trial or goes straight to a paid period.
     */
    private function applyCommercialChoices(Registration $registration, Subscription $subscription, Plan $plan): Subscription
    {
        $options = $registration->options ?? [];
        $cycle = is_string($options['cycle'] ?? null) && $plan->offers($options['cycle']) ? $options['cycle'] : null;
        if ($cycle === null) {
            return $subscription;
        }

        // Anyone may choose the cycle; only a Super Admin may skip the trial.
        $values = ['billing_period_snapshot' => $cycle, 'price_minor_snapshot' => $plan->priceFor($cycle), 'currency_snapshot' => $plan->currency];
        if ($registration->source === 'platform' && ($options['trial'] ?? true) === false) {
            $start = is_string($options['starts_at'] ?? null) && $options['starts_at'] !== '' ? Carbon::parse($options['starts_at']) : now();
            $values += [
                'status' => SubscriptionStatus::Active,
                'trial_starts_at' => null,
                'trial_ends_at' => null,
                'current_period_start' => $start,
                'current_period_end' => $cycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow(),
            ];
        }
        $subscription->forceFill($values)->save();

        return $subscription;
    }

    /** Features a Super Admin added to, or removed from, the plan at creation. */
    private function applyFeatureChoices(Registration $registration, TenantModel $tenant, Audit $audit): void
    {
        $options = $registration->options ?? [];
        $choices = ['grant' => $options['grants'] ?? [], 'revoke' => $options['revokes'] ?? []];
        $actor = Actor::system('center-created-by-platform');
        foreach ($choices as $mode => $keys) {
            foreach (is_array($keys) ? $keys : [] as $key) {
                if (! is_string($key)) {
                    continue;
                }
                /** @var TenantEntitlementOverride $override */
                $override = TenantEntitlementOverride::query()->updateOrCreate(
                    ['tenant_id' => $tenant->getTenantKey(), 'entitlement' => $key],
                    ['mode' => $mode, 'source' => 'platform', 'reason' => 'Chosen when the center was created by '.($registration->created_by_label ?? 'the platform'), 'starts_at' => now(), 'expires_at' => null],
                );
                $audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
                    action: 'platform.entitlement.override.set',
                    category: AuditCategory::Config,
                    actor: $actor,
                    targetType: TenantEntitlementOverride::class,
                    targetId: (string) $override->id,
                    targetLabel: $key,
                    after: ['mode' => $mode],
                ));
            }
        }
    }

    private function sendOwnerInvitation(
        Registration $registration,
        TenantModel $tenant,
        Plan $plan,
        PlatformHosts $hosts,
        StanclTenantContext $context,
        IssueCenterPasswordLink $links,
    ): void {
        if ($registration->owner_email === null || $tenant->slug === null) {
            return;
        }
        $slug = $tenant->slug;
        $setupUrl = $context->runForModel($tenant, function () use ($links, $registration, $slug): ?string {
            /** @var User|null $owner */
            $owner = User::query()->where('is_owner', true)->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $registration->owner_email)])->first();

            return $owner instanceof User ? $links($owner, $slug, 72 * 60) : null;
        });
        if (! is_string($setupUrl)) {
            return;
        }

        Mail::to($registration->owner_email)->locale($registration->locale)->queue(new CenterOwnerInvitationEmail(
            ownerName: $registration->owner_name,
            centerName: $registration->center_name,
            loginEmail: $registration->owner_email,
            setupUrl: $setupUrl,
            loginUrl: $hosts->centerUrl($slug, '/login'),
            publicUrl: $hosts->centerUrl($slug),
            planName: $plan->name->get($registration->locale),
        ));
    }

    private function sendConfirmation(
        Registration $registration,
        TenantModel $tenant,
        Plan $plan,
        PlatformHosts $hosts,
    ): void {
        if ($registration->owner_email === null || $tenant->slug === null) {
            return;
        }

        Mail::to($registration->owner_email)->locale($registration->locale)->queue(new CenterCreatedEmail(
            ownerName: $registration->owner_name,
            centerName: $registration->center_name,
            loginEmail: $registration->owner_email,
            publicUrl: $hosts->centerUrl($tenant->slug),
            loginUrl: $hosts->centerUrl($tenant->slug, '/login'),
            planName: $plan->name->get($registration->locale),
        ));
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
