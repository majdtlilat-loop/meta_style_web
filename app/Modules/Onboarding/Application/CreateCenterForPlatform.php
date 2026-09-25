<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\Tenancy\Infrastructure\DomainModel;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Onboarding\Domain\Exceptions\CenterSlugUnavailable;
use App\Modules\Onboarding\Domain\Exceptions\OwnerPhoneRequired;
use App\Modules\Onboarding\Infrastructure\Jobs\ProvisionRegisteredTenant;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A Super Admin creates a center directly.
 *
 * It is a registration like any other and goes through the SAME provisioning
 * pipeline ({@see ProvisionRegisteredTenant}) — database, schema, roles, main
 * branch, owner, subscription. Two things differ:
 *
 *  - nobody chooses a password here. The owner is created with a random,
 *    never-shown credential and is emailed a one-time link to set their own
 *    password once the center exists. No password is typed for them, stored
 *    readable or sent by email;
 *  - the Super Admin's commercial choices (billing cycle, trial or paid,
 *    currency, languages, timezone) ride on the registration as `options` and
 *    are applied by the pipeline once the tenant exists.
 *
 * The owner's email is vouched for by the Super Admin, so no verification
 * round-trip is needed before provisioning starts.
 */
final class CreateCenterForPlatform
{
    public function __construct(
        private readonly PlatformHosts $hosts,
        private readonly PlatformCurrencies $currencies,
        private readonly LanguageRegistry $languages,
        private readonly Audit $audit,
        private readonly EntitlementCatalog $catalog,
    ) {}

    /**
     * @param  array{center_name: string, slug: string, owner_name: string, owner_email: string, owner_phone: string, primary_locale: string, locales: list<string>, currency: string, timezone: string, plan_id: int, cycle: string, trial: bool, trial_days: int|null, starts_at: string|null, grants?: list<string>, revokes?: list<string>}  $input
     */
    public function __invoke(array $input, Actor $actor): Registration
    {
        $slug = $this->hosts->normalizeSlug($input['slug']);
        if (! $this->slugAvailable($slug)) {
            throw new CenterSlugUnavailable(__('sadmin_centers.errors.slug_taken'));
        }

        // Required: the owner account is created with this phone.
        $ownerPhone = PhoneNumber::parse($input['owner_phone']);
        if (! $ownerPhone instanceof PhoneNumber) {
            throw OwnerPhoneRequired::make();
        }

        $plan = Plan::query()->whereKey($input['plan_id'])->where('is_active', true)->first();
        if (! $plan instanceof Plan) {
            throw new DomainException(__('sadmin_centers.errors.plan'));
        }
        if (! $plan->offers($input['cycle'])) {
            throw new DomainException(__('sadmin_subscriptions.errors.cycle_not_offered'));
        }
        if (! in_array($input['currency'], $this->currencies->centerCodes(), true)) {
            throw new DomainException(__('sadmin_centers.errors.currency'));
        }
        $locales = array_values(array_unique(array_filter($input['locales'], fn (string $locale): bool => $this->languages->supports($locale))));
        if ($locales === [] || ! in_array($input['primary_locale'], $locales, true)) {
            throw new DomainException(__('sadmin_centers.errors.locales'));
        }
        if (! in_array($input['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException(__('sadmin_centers.errors.timezone'));
        }

        $grants = array_values(array_unique(array_filter($input['grants'] ?? [], fn (string $key): bool => $this->catalog->has($key))));
        $revokes = array_values(array_unique(array_filter($input['revokes'] ?? [], fn (string $key): bool => $this->catalog->has($key))));

        $registration = new Registration;
        $registration->forceFill([
            'idempotency_key' => 'platform:'.Str::uuid(),
            'status' => RegistrationStatus::Preparing,
            'source' => 'platform',
            'center_name' => trim($input['center_name']),
            'requested_slug' => $slug,
            'owner_name' => trim($input['owner_name']),
            'owner_email' => mb_strtolower(trim($input['owner_email'])),
            'owner_phone' => $ownerPhone->e164,
            'locale' => $input['primary_locale'],
            'selected_plan_id' => $plan->id,
            'options' => [
                'locales' => $locales,
                'currency' => $input['currency'],
                'timezone' => $input['timezone'],
                'cycle' => $input['cycle'],
                'trial' => $input['trial'],
                'trial_days' => $input['trial'] ? $input['trial_days'] : null,
                'starts_at' => $input['starts_at'],
                // Explicit differences from the plan, applied as audited
                // platform overrides once the center exists.
                'grants' => $grants,
                'revokes' => $revokes,
            ],
            'created_by_label' => $actor->label,
            // A random credential nobody knows: the owner sets their own
            // password from the emailed link. Hashed here, never stored readable.
            'owner_password_hash' => Hash::make(Str::random(64)),
            'credentials_expire_at' => now()->addDay(),
            'email_verified_at' => now(),
        ]);

        DB::connection('control')->transaction(static function () use ($registration): void {
            $registration->save();
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.center.create_requested',
            category: AuditCategory::Tenancy,
            actor: $actor,
            targetType: Registration::class,
            targetId: $registration->uuid,
            targetLabel: $registration->center_name,
            after: [
                'slug' => $slug,
                'plan' => $plan->code,
                'cycle' => $input['cycle'],
                'trial' => $input['trial'],
                'currency' => $input['currency'],
                'locales' => $locales,
                'grants' => $grants,
                'revokes' => $revokes,
            ],
        ));

        ProvisionRegisteredTenant::dispatch($registration->uuid);

        return $registration;
    }

    public function slugAvailable(string $slug): bool
    {
        if (! $this->hosts->isValidCenterSlug($slug)) {
            return false;
        }

        return ! TenantModel::query()->where('slug', $slug)->exists()
            && ! DomainModel::query()->where('domain', $this->hosts->centerHost($slug))->exists()
            // Any registration, finished or not, holds its address (unique).
            && ! Registration::query()->where('requested_slug', $slug)->exists();
    }
}
