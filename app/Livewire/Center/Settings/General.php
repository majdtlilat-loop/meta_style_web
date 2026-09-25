<?php

declare(strict_types=1);

namespace App\Livewire\Center\Settings;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Currencies\PlatformCurrency;
use App\Kernel\Tenancy\Actions\UpdateOwnCenterProfile;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\RegisteredCenterAddress;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Settings → General: the center's own profile — name, primary contact,
 * timezone, and the operational currency while no money has moved.
 *
 * The address is shown, never edited here: it is the platform's to change,
 * because links and QR codes are printed against it. Every write goes through
 * UpdateOwnCenterProfile, which checks `settings.manage`, writes only the
 * bound tenant's own row and audits contact details as fingerprints.
 */
final class General extends Component
{
    public string $name = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $phoneNumber = '';

    public string $phoneCountry = 'IQ';

    public string $timezone = '';

    public string $currency = '';

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(): void
    {
        $this->viewer();
        $this->fillFrom($this->tenant());
    }

    public function save(UpdateOwnCenterProfile $update): void
    {
        $this->resetErrorBag();
        $number = trim($this->phoneNumber);

        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'contactName' => ['nullable', 'string', 'max:190'],
            'contactEmail' => ['nullable', 'email', 'max:190'],
            'phoneNumber' => $number === '' ? ['nullable'] : ['string', new PhoneRule($this->phoneCountry)],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'currency' => ['nullable', 'string', 'max:8'],
        ], [], [
            'name' => __('manager_settings.profile.name'),
            'contactName' => __('manager_settings.profile.contact_name'),
            'contactEmail' => __('manager_settings.profile.contact_email'),
            'phoneNumber' => __('manager_settings.profile.contact_phone'),
            'timezone' => __('manager_settings.profile.timezone'),
            'currency' => __('manager_settings.profile.currency'),
        ]);

        try {
            $tenant = $update($this->viewer(), [
                'name' => $this->name,
                'contact_name' => $this->contactName,
                'contact_email' => $this->contactEmail,
                'contact_phone' => $number === '' ? '' : (string) PhoneNumber::fromParts($this->phoneCountry, $number)?->e164,
                'timezone' => $this->timezone,
                'currency' => $this->currency,
            ]);
        } catch (AuthorizationException) {
            $this->notice = __('manager_settings.errors.forbidden');
            $this->noticeTone = 'danger';

            return;
        } catch (ValidationException $e) {
            $map = ['name' => 'name', 'contact_name' => 'contactName', 'contact_email' => 'contactEmail', 'contact_phone' => 'phoneNumber', 'timezone' => 'timezone', 'currency' => 'currency'];

            foreach ($e->errors() as $field => $messages) {
                $this->addError($map[$field] ?? 'name', (string) ($messages[0] ?? ''));
            }

            return;
        }

        $this->fillFrom($tenant);
        $this->notice = __('manager_settings.profile.saved');
        $this->noticeTone = 'success';
    }

    public function render(UpdateOwnCenterProfile $profile, PlatformCurrencies $currencies, RegisteredCenterAddress $addresses): View
    {
        $user = $this->viewer();
        $tenant = $this->tenant();
        $address = $addresses->resolve($tenant);
        $locale = app()->getLocale();

        return view('livewire.center.settings.general', [
            'canManage' => $user->hasPermission(Permission::SettingsManage),
            'currencyLocked' => $profile->currencyLocked(),
            'currencies' => array_map(static function (string $code) use ($currencies, $locale): array {
                $currency = $currencies->find($code);

                return ['code' => $code, 'label' => $currency instanceof PlatformCurrency ? $code.' · '.$currency->name->get($locale) : $code];
            }, $currencies->centerCodes()),
            'currentCurrency' => $tenant->currency ?: $currencies->defaultCode(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'address' => $address['available'] ? ($address['urls']['public'] ?? null) : null,
        ]);
    }

    private function fillFrom(TenantModel $tenant): void
    {
        $this->name = (string) $tenant->name;
        $this->contactName = (string) $tenant->contact_name;
        $this->contactEmail = (string) $tenant->contact_email;
        $this->timezone = (string) $tenant->timezone;
        $this->currency = (string) $tenant->currency;

        $phone = PhoneNumber::parse($tenant->contact_phone);
        $this->phoneCountry = $phone?->country() ?? 'IQ';
        $this->phoneNumber = $phone?->national() ?? '';
    }

    private function tenant(): TenantModel
    {
        return TenantModel::query()->with('domains')->findOrFail(app(TenantContext::class)->require()->id);
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission(Permission::SettingsView), 403);

        return $user;
    }
}
