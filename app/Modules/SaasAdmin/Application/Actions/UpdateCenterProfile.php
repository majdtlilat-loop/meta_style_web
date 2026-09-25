<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\Tenancy\Infrastructure\DomainModel;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\PlatformHosts;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * What a Super Admin may change about a center's platform record.
 *
 * Name, primary contact and timezone are control-plane metadata. The
 * operational CURRENCY is guarded: a center's prices, sales and payments are
 * recorded as minor units of it, so once money has moved it is locked —
 * changing it then would silently re-denominate every amount on record. The
 * languages are the center's own `TenantLocales`; disabling one never deletes
 * a stored translation. The ADDRESS change keeps the old host attached, so
 * links and QR codes already printed keep reaching the center.
 *
 * Every change is audited against the center.
 */
final class UpdateCenterProfile
{
    public function __construct(
        private readonly Audit $audit,
        private readonly PlatformCurrencies $currencies,
        private readonly LanguageRegistry $languages,
        private readonly PlatformHosts $hosts,
        private readonly StanclTenantContext $context,
    ) {}

    /**
     * @param  array{name: string, contact_name: string|null, contact_email: string|null, contact_phone: string|null, timezone: string|null, currency: string|null}  $data
     */
    public function update(TenantModel $tenant, array $data, Actor $actor, string $reason): TenantModel
    {
        $reason = $this->reason($reason);
        $name = trim($data['name']);
        $email = $data['contact_email'] !== null && trim($data['contact_email']) !== '' ? mb_strtolower(trim($data['contact_email'])) : null;
        if ($name === '' || mb_strlen($name) > 190 || ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new DomainException(__('sadmin_centers.errors.profile'));
        }
        if ($data['timezone'] !== null && ! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException(__('sadmin_centers.errors.timezone'));
        }

        $currency = $data['currency'] !== null && $data['currency'] !== '' ? mb_strtoupper($data['currency']) : null;
        $currentCurrency = $tenant->currency;
        if ($currency !== $currentCurrency) {
            if ($currency !== null && ! in_array($currency, $this->currencies->centerCodes(), true)) {
                throw new DomainException(__('sadmin_centers.errors.currency'));
            }
            if ($this->currencyLocked($tenant)) {
                throw new DomainException(__('sadmin_centers.errors.currency_locked'));
            }
        }

        $before = $tenant->only(['name', 'contact_name', 'contact_email', 'contact_phone', 'timezone', 'currency']);
        $tenant->forceFill([
            'name' => $name,
            'contact_name' => $this->nullable($data['contact_name'], 190),
            'contact_email' => $email,
            'contact_phone' => $this->nullable($data['contact_phone'], 48),
            'timezone' => $data['timezone'],
            'currency' => $currency,
        ])->save();
        $after = $tenant->only(['name', 'contact_name', 'contact_email', 'contact_phone', 'timezone', 'currency']);

        // Contact details are personal data: the audit records THAT they
        // changed, not what they are.
        $redact = static fn (array $values): array => array_merge($values, [
            'contact_email' => $values['contact_email'] === null ? null : '•',
            'contact_phone' => $values['contact_phone'] === null ? null : '•',
        ]);

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'platform.center.profile.updated',
            category: AuditCategory::Tenancy,
            actor: $actor,
            severity: $currency !== $currentCurrency ? AuditSeverity::Warning : AuditSeverity::Notice,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            before: $redact($before),
            after: $redact($after),
            reason: $reason,
        ));

        return $tenant;
    }

    /**
     * Money has moved in this center: its currency can no longer change.
     */
    public function currencyLocked(TenantModel $tenant): bool
    {
        if (! $tenant->toValueObject()->isProvisioned()) {
            return false;
        }

        return (bool) $this->context->runForModel($tenant, static fn (): bool => DB::connection('tenant')->table('sales')->exists()
            || DB::connection('tenant')->table('payments')->exists());
    }

    /**
     * @param  list<string>  $locales
     */
    public function setLanguages(TenantModel $tenant, array $locales, string $primary, Actor $actor, string $reason): void
    {
        $reason = $this->reason($reason);
        $locales = array_values(array_unique(array_filter($locales, fn (string $locale): bool => $this->languages->supports($locale))));
        if ($locales === [] || ! in_array($primary, $locales, true)) {
            throw new DomainException(__('sadmin_centers.errors.locales'));
        }

        $before = $this->context->runForModel($tenant, static function () use ($locales, $primary): array {
            $service = app(TenantLocales::class);
            $before = ['enabled' => $service->enabled(), 'primary' => $service->default()];
            $service->setEnabled($locales, $primary);
            $service->forget();

            return $before;
        });

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'platform.center.languages.updated',
            category: AuditCategory::Config,
            actor: $actor,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            before: $before,
            after: ['enabled' => $locales, 'primary' => $primary],
            reason: $reason,
        ));
    }

    /** @return array{enabled: list<string>, primary: string} */
    public function languages(TenantModel $tenant): array
    {
        if (! $tenant->toValueObject()->isProvisioned()) {
            return ['enabled' => [], 'primary' => ''];
        }

        return $this->context->runForModel($tenant, static function (): array {
            $service = app(TenantLocales::class);

            return ['enabled' => $service->enabled(), 'primary' => $service->default()];
        });
    }

    public function changeAddress(TenantModel $tenant, string $slug, Actor $actor, string $reason): TenantModel
    {
        $reason = $this->reason($reason);
        $slug = $this->hosts->normalizeSlug($slug);
        if ($slug === $tenant->slug) {
            return $tenant;
        }
        if (! $this->slugAvailable($slug)) {
            throw new DomainException(__('sadmin_centers.errors.slug_taken'));
        }

        $before = $tenant->slug;
        DB::connection('control')->transaction(function () use ($tenant, $slug): void {
            DomainModel::query()->where('tenant_id', $tenant->getTenantKey())->update(['is_primary' => false]);
            DomainModel::query()->create(['domain' => $this->hosts->centerHost($slug), 'tenant_id' => $tenant->getTenantKey(), 'is_primary' => true]);
            $tenant->forceFill(['slug' => $slug])->save();
        });

        $this->audit->recordForTenant($tenant->getTenantKey(), new AuditEvent(
            action: 'platform.center.address.changed',
            category: AuditCategory::Tenancy,
            actor: $actor,
            severity: AuditSeverity::Warning,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            before: ['slug' => $before],
            after: ['slug' => $slug],
            reason: $reason,
        ));

        return $tenant;
    }

    public function slugAvailable(string $slug): bool
    {
        if (! $this->hosts->isValidCenterSlug($slug)) {
            return false;
        }

        return ! TenantModel::query()->where('slug', $slug)->exists()
            && ! DomainModel::query()->where('domain', $this->hosts->centerHost($slug))->exists()
            && ! Registration::query()->where('requested_slug', $slug)->exists();
    }

    private function nullable(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException(__('sadmin_centers.errors.reason'));
        }

        return $reason;
    }
}
