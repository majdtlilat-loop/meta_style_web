<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Privacy\Fingerprint;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A center edits its OWN profile from the Manager: its name, its primary
 * contact, its timezone — and its operational currency while that is still
 * safe to change.
 *
 * ## A control-plane write from tenant context
 *
 * These are columns on the control-plane `tenants` row, which until now only
 * the Super Admin changed (SaasAdmin's UpdateCenterProfile). The row written
 * is ALWAYS the bound tenant's, looked up by the id tenancy resolved from the
 * host — never an identifier from the request — so there is no parameter that
 * could point this at another center.
 *
 * ## What stays out of reach
 *
 *  - The address (slug) is platform-only: links and QR codes are printed
 *    against it, and changing it keeps the old host alive, which is the
 *    platform's call.
 *  - The currency is locked once money has moved — a sale or a payment exists.
 *    The same rule the Super Admin is held to (UpdateCenterProfile::
 *    currencyLocked): every amount on record is minor units of it, so
 *    changing it then would silently re-denominate them all. Before that it
 *    may be any currency the platform offers centers.
 *
 * ## Audit
 *
 * Recorded in the center's own log and in the platform's (the row lives in the
 * control plane). The contact email and phone are PERSONAL DATA: the audit
 * keeps a keyed fingerprint of each, enough to tell that it changed and to
 * correlate, never the value (docs/08-AUDIT-SECURITY.md §17).
 */
final class UpdateOwnCenterProfile
{
    public const FIELDS = ['name', 'contact_name', 'contact_email', 'contact_phone', 'timezone', 'currency'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PlatformCurrencies $currencies,
        private readonly Audit $audit,
    ) {}

    /**
     * Omitted keys are left as they are. `contact_phone` is E.164, built by
     * the form with PhoneNumber::fromParts().
     *
     * @param  array{name?: string|null, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null, timezone?: string|null, currency?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actingUser, array $data): TenantModel
    {
        if (! $actingUser->hasPermission(Permission::SettingsManage)) {
            throw new AuthorizationException(__('manager_settings.errors.forbidden'));
        }

        $tenant = TenantModel::query()->findOrFail($this->tenants->require()->id);

        $values = $tenant->only(self::FIELDS);
        $errors = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '' || mb_strlen($name) > 190) {
                $errors['name'] = __('manager_settings.profile.errors.name');
            }

            $values['name'] = $name;
        }

        if (array_key_exists('contact_name', $data)) {
            $contact = trim((string) $data['contact_name']);

            if (mb_strlen($contact) > 190) {
                $errors['contact_name'] = __('manager_settings.profile.errors.contact_name');
            }

            $values['contact_name'] = $contact === '' ? null : $contact;
        }

        if (array_key_exists('contact_email', $data)) {
            $email = mb_strtolower(trim((string) $data['contact_email']));

            if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $errors['contact_email'] = __('manager_settings.profile.errors.contact_email');
            }

            $values['contact_email'] = $email === '' ? null : $email;
        }

        if (array_key_exists('contact_phone', $data)) {
            $raw = trim((string) $data['contact_phone']);
            $phone = $raw === '' ? null : PhoneNumber::parse($raw);

            // Stored canonical, never as typed: the caller passes the E.164
            // value the phone field produced, and anything that does not
            // survive parsing is refused rather than kept half-formed.
            if ($raw !== '' && ($phone === null || $phone->e164 !== $raw)) {
                $errors['contact_phone'] = __('phone_field.errors.invalid');
            }

            $values['contact_phone'] = $phone?->e164;
        }

        if (array_key_exists('timezone', $data)) {
            $timezone = trim((string) $data['timezone']);

            if ($timezone !== '' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                $errors['timezone'] = __('manager_settings.profile.errors.timezone');
            }

            $values['timezone'] = $timezone === '' ? null : $timezone;
        }

        $currencyChanged = false;

        if (array_key_exists('currency', $data)) {
            $currency = mb_strtoupper(trim((string) $data['currency']));
            $currency = $currency === '' ? null : $currency;

            if ($currency !== $tenant->currency) {
                if ($currency === null || ! in_array($currency, $this->currencies->centerCodes(), true)) {
                    $errors['currency'] = __('manager_settings.profile.errors.currency');
                } elseif ($this->currencyLocked()) {
                    $errors['currency'] = __('manager_settings.profile.errors.currency_locked');
                } else {
                    $currencyChanged = true;
                }
            }

            $values['currency'] = $currency;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = $tenant->only(self::FIELDS);

        if ($before == $values) {
            return $tenant;
        }

        $tenant->forceFill($values)->save();

        $event = new AuditEvent(
            action: 'center.profile.updated',
            category: AuditCategory::Tenancy,
            actor: Actor::staff($actingUser),
            severity: $currencyChanged ? AuditSeverity::Warning : AuditSeverity::Notice,
            targetType: TenantModel::class,
            targetId: $tenant->getTenantKey(),
            targetLabel: $tenant->name,
            before: $this->redact($before),
            after: $this->redact($tenant->only(self::FIELDS)),
        );

        // The center sees it in its own log; the platform keeps its record of
        // a change to a control-plane row.
        $this->audit->record($event);
        $this->audit->recordForTenant($tenant->getTenantKey(), $event);

        return $tenant;
    }

    /**
     * Money has moved in this center: its currency can no longer change. The
     * same test as the Super Admin's (UpdateCenterProfile::currencyLocked),
     * asked of the bound tenant's own database.
     */
    public function currencyLocked(): bool
    {
        $this->tenants->require();

        return DB::connection('tenant')->table('sales')->exists()
            || DB::connection('tenant')->table('payments')->exists();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $values['contact_email'] = Fingerprint::of(is_string($values['contact_email'] ?? null) ? $values['contact_email'] : null);
        $values['contact_phone'] = Fingerprint::of(is_string($values['contact_phone'] ?? null) ? $values['contact_phone'] : null);

        return $values;
    }
}
