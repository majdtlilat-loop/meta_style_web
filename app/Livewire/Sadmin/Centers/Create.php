<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Centers;

use App\Kernel\Audit\Actor;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Money\Currency;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\TrialPolicy;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\Onboarding\Application\CreateCenterForPlatform;
use App\Modules\Onboarding\Domain\Exceptions\CenterSlugUnavailable;
use App\Modules\Onboarding\Domain\Exceptions\OwnerPhoneRequired;
use DateTimeZone;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Add center": everything the platform needs to create a center, in one
 * form. Submitting hands a registration to the same provisioning pipeline a
 * self-registration uses; the owner is emailed a link to set their password.
 */
#[Layout('layouts.superadmin.app')]
final class Create extends Component
{
    use AuthorizesPlatform;

    public string $name = '';

    public string $slug = '';

    public bool $slugEdited = false;

    public string $primaryLocale = 'ar';

    /** @var array<int, string> */
    public array $locales = ['ar'];

    public string $currency = '';

    public string $timezone = '';

    public string $ownerName = '';

    public string $ownerEmail = '';

    public string $ownerPhone = '';

    /** The phone field's country; Iraq unless changed. */
    public string $ownerPhoneCountry = PhoneCountries::DEFAULT;

    public ?int $planId = null;

    public string $cycle = 'monthly';

    public bool $trial = true;

    public string $trialDays = '';

    public string $startsAt = '';

    /** @var list<string> The capabilities the center will have: the plan's, adjusted. */
    public array $features = [];

    public function mount(PlatformPreferences $preferences, PlatformCurrencies $currencies): void
    {
        $this->requirePlatformPermission('platform.center.manage');
        $general = $preferences->general();
        $this->primaryLocale = $general['default_locale'];
        $this->locales = [$general['default_locale']];
        $this->timezone = $general['default_timezone'];
        $this->currency = in_array($currencies->defaultCode(), $currencies->centerCodes(), true) ? $currencies->defaultCode() : Currency::default()->value;
        $this->startsAt = now()->format('Y-m-d');
        $default = Plan::query()->where('is_active', true)->where('code', PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE))->first()
            ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->first();
        $this->planId = $default?->id;
        $this->cycle = $default?->offers('monthly') ? 'monthly' : 'yearly';
        $this->features = $default instanceof Plan ? $default->entitlementCodes() : [];
    }

    public function updatedName(PlatformHosts $hosts): void
    {
        if (! $this->slugEdited) {
            $this->slug = $hosts->normalizeSlug($this->name);
        }
    }

    public function updatedSlug(PlatformHosts $hosts): void
    {
        $this->slugEdited = true;
        $this->slug = $hosts->normalizeSlug($this->slug);
    }

    public function updatedPlanId(): void
    {
        $plan = Plan::query()->find($this->planId);
        if ($plan instanceof Plan && ! $plan->offers($this->cycle)) {
            $this->cycle = $plan->cycles()[0] ?? 'monthly';
        }
        $this->features = $plan instanceof Plan ? $plan->entitlementCodes() : [];
    }

    public function updatedLocales(): void
    {
        $this->locales = array_values(array_unique($this->locales));
        if (! in_array($this->primaryLocale, $this->locales, true)) {
            $this->primaryLocale = $this->locales[0] ?? '';
        }
    }

    public function create(CreateCenterForPlatform $create, LanguageRegistry $languages, PlatformCurrencies $currencies): mixed
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string', 'in:'.implode(',', $languages->supported())],
            'primaryLocale' => ['required', 'string', 'in:'.implode(',', $this->locales)],
            'currency' => ['required', 'string', 'in:'.implode(',', $currencies->centerCodes())],
            'timezone' => ['required', 'string', 'timezone:all'],
            'ownerName' => ['required', 'string', 'max:190'],
            'ownerEmail' => ['required', 'email:rfc', 'max:190'],
            'ownerPhoneCountry' => ['required', 'string', 'size:2'],
            'ownerPhone' => ['required', 'string', 'max:32', new PhoneRule($this->ownerPhoneCountry)],
            'planId' => ['required', 'integer'],
            'cycle' => ['required', 'in:monthly,yearly'],
            'trial' => ['boolean'],
            'trialDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'startsAt' => [$this->trial ? 'nullable' : 'required', 'nullable', 'date'],
        ], [], $this->attributeNames());

        $planFeatures = Plan::query()->find($this->planId)?->entitlementCodes() ?? [];

        try {
            $registration = $create([
                'center_name' => $this->name,
                'slug' => $this->slug,
                'owner_name' => $this->ownerName,
                'owner_email' => $this->ownerEmail,
                // The owner's phone is required and stored in E.164 (+9647501234567).
                'owner_phone' => (string) PhoneNumber::fromParts($this->ownerPhoneCountry, $this->ownerPhone)?->e164,
                'primary_locale' => $this->primaryLocale,
                'locales' => array_values($this->locales),
                'currency' => $this->currency,
                'timezone' => $this->timezone,
                'plan_id' => (int) $this->planId,
                'cycle' => $this->cycle,
                'trial' => $this->trial,
                'trial_days' => $this->trial && $this->trialDays !== '' ? (int) $this->trialDays : null,
                'starts_at' => $this->trial ? null : $this->startsAt,
                'grants' => array_values(array_diff($this->features, $planFeatures)),
                'revokes' => array_values(array_diff($planFeatures, $this->features)),
            ], Actor::platform($user));
        } catch (CenterSlugUnavailable $exception) {
            $this->addError('slug', $exception->getMessage());

            return null;
        } catch (OwnerPhoneRequired $exception) {
            $this->addError('ownerPhone', $exception->getMessage());

            return null;
        } catch (DomainException $exception) {
            $this->addError('planId', $exception->getMessage());

            return null;
        }

        session()->flash('notice', __('sadmin_centers.create.started', ['name' => $registration->center_name]));

        return $this->redirectRoute('superadmin.centers.index', navigate: true);
    }

    public function render(PlatformHosts $hosts, PlatformCurrencies $currencies, LanguageRegistry $languages, EntitlementCatalog $catalog, TrialPolicy $trials): mixed
    {
        $this->requirePlatformPermission('platform.center.manage');
        $plans = Plan::query()->with('entitlements')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $plan = $plans->firstWhere('id', (int) $this->planId);
        $slugValid = $this->slug !== '' && $hosts->isValidCenterSlug($this->slug);

        return view('livewire.sadmin.centers.create', [
            'plans' => $plans,
            'plan' => $plan,
            'host' => $slugValid ? $hosts->centerHost($this->slug) : null,
            'baseHost' => $hosts->baseDomain(),
            'slugAvailable' => $slugValid ? app(CreateCenterForPlatform::class)->slugAvailable($this->slug) : null,
            'currencies' => $currencies->centerCodes(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'languages' => collect($languages->supported())->mapWithKeys(fn (string $code): array => [$code => $languages->nativeName($code)])->all(),
            'planFeatures' => $plan instanceof Plan ? $plan->entitlements->pluck('entitlement')->all() : [],
            'catalog' => collect($catalog->keys())->groupBy(fn (string $key): string => $catalog->category($key))->all(),
            'defaultTrialDays' => $trials->daysFor($plan instanceof Plan ? $plan : null),
            'money' => $currencies,
        ]);
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'name' => __('sadmin_centers.form.name'),
            'slug' => __('sadmin_centers.form.slug'),
            'locales' => __('sadmin_centers.form.languages'),
            'primaryLocale' => __('sadmin_centers.form.primary_language'),
            'currency' => __('sadmin_centers.form.currency'),
            'timezone' => __('sadmin_centers.form.timezone'),
            'ownerName' => __('sadmin_centers.form.owner_name'),
            'ownerEmail' => __('sadmin_centers.form.owner_email'),
            'ownerPhone' => __('sadmin_centers.form.owner_phone'),
            'ownerPhoneCountry' => __('phone_field.country'),
            'planId' => __('sadmin_centers.form.plan'),
            'cycle' => __('sadmin_centers.plan.cycle'),
            'trialDays' => __('sadmin_centers.form.trial_days'),
            'startsAt' => __('sadmin_centers.form.starts_at'),
        ];
    }
}
