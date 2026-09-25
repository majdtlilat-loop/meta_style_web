<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Settings;

use App\Kernel\Audit\Actor;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasAdmin\Application\Actions\UpdatePlatformSettings;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Platform settings: explicit, validated, audited — never an environment
 * editor. Infrastructure such as hosts and the mail transport stays in
 * deployment configuration and is only reported here. Branding, AI and the
 * invoice template are separate pages (Settings\Branding, Settings\Ai,
 * Settings\InvoiceTemplate), each behind its own permission.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;

    public const TABS = ['general', 'commercial', 'security', 'email', 'notifications', 'localization'];

    #[Url(except: 'general')]
    public string $tab = 'general';

    /** @var array{platform_name: string, support_email: string, support_phone: string, default_locale: string, default_timezone: string} */
    public array $general = ['platform_name' => '', 'support_email' => '', 'support_phone' => '', 'default_locale' => 'en', 'default_timezone' => 'Asia/Baghdad'];

    public int $trialDays = 14;

    public string $defaultPlanCode = '';

    public string $reason = '';

    /** `mfa-off`, `mfa-on`. */
    public ?string $panel = null;

    public string $currentPassword = '';

    /** @var array<string, array{in_app: bool, email: bool}> */
    public array $rules = [];

    public function mount(PlatformPreferences $preferences): mixed
    {
        // The AI section moved to its own page; old links still land there.
        if ($this->tab === 'ai') {
            return $this->redirectRoute('superadmin.settings.ai', navigate: true);
        }
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'general';
        }
        $this->general = $preferences->general();
        $this->trialDays = (int) PlatformSetting::get(PlatformSetting::DEFAULT_TRIAL_DAYS, config('metastyle.saas.default_trial_days'));
        $this->defaultPlanCode = (string) PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE, config('metastyle.saas.default_plan_code'));
        $this->rules = $preferences->notificationRules();

        return null;
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'general';
        $this->closePanel();
    }

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.security.manage');
        $this->closePanel();
        $this->panel = in_array($panel, ['mfa-off', 'mfa-on'], true) ? $panel : null;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('currentPassword', 'reason');
        $this->resetValidation();
    }

    public function saveGeneral(PlatformPreferences $preferences, LanguageRegistry $languages): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $this->validate([
            'general.platform_name' => ['required', 'string', 'max:80'],
            'general.support_email' => ['nullable', 'email:rfc', 'max:190'],
            'general.support_phone' => ['nullable', 'string', 'max:32'],
            'general.default_locale' => ['required', 'in:'.implode(',', $languages->supported())],
            'general.default_timezone' => ['required', 'timezone:all'],
        ], [], [
            'general.platform_name' => __('platform_settings.general.name'),
            'general.support_email' => __('platform_settings.general.support_email'),
            'general.support_phone' => __('platform_settings.general.support_phone'),
            'general.default_timezone' => __('platform_settings.general.timezone'),
        ]);
        $this->attempt(fn () => $preferences->saveGeneral($this->general, $languages->supported(), Actor::platform($user)), 'general.platform_name');
    }

    /** Registration defaults: trial length and the plan new centers start on. */
    public function save(UpdatePlatformSettings $update): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $data = $this->validate([
            'trialDays' => ['required', 'integer', 'min:0', 'max:365'],
            'defaultPlanCode' => ['required', 'string', 'exists:control.plans,code'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $update($data['trialDays'], $data['defaultPlanCode'], Actor::platform($user), $data['reason']);
        $this->reset('reason');
        session()->flash('notice', __('platform_settings.saved'));
    }

    public function setMfa(PlatformPreferences $preferences): void
    {
        $user = $this->requirePlatformPermission('platform.security.manage');
        $disabling = $this->panel === 'mfa-off';
        $rules = ['reason' => ['required', 'string', 'min:5', 'max:1000']];
        if ($disabling) {
            $rules['currentPassword'] = ['required', 'string'];
        }
        $this->validate($rules, [], ['reason' => __('platform_settings.reason'), 'currentPassword' => __('platform_settings.security.password')]);

        // Weakening sign-in is confirmed with the person's own password.
        if ($disabling && ! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', __('platform_settings.security.password_wrong'));

            return;
        }

        $this->attempt(fn () => $preferences->setMfaRequired(! $disabling, Actor::platform($user), $this->reason), 'reason', $disabling ? __('platform_settings.security.disabled') : __('platform_settings.security.enabled'));
    }

    public function sendTestEmail(): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        try {
            Mail::raw(__('platform_settings.email.test_body'), function ($message) use ($user): void {
                $message->to($user->email)->subject(__('platform_settings.email.test_subject'));
            });
            session()->flash('notice', __('platform_settings.email.test_sent', ['email' => $user->email]));
        } catch (Throwable) {
            session()->flash('notice-error', __('platform_settings.email.test_failed'));
        }
    }

    public function saveNotifications(PlatformPreferences $preferences): void
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $this->attempt(fn () => $preferences->saveNotificationRules($this->rules, Actor::platform($user)), 'rules');
    }

    public function render(PlatformHosts $hosts, LanguageRegistry $languages, PlatformPreferences $preferences): mixed
    {
        $user = $this->requirePlatformPermission('platform.settings.manage');
        $savedTrial = (int) PlatformSetting::get(PlatformSetting::DEFAULT_TRIAL_DAYS, config('metastyle.saas.default_trial_days'));
        $savedPlan = (string) PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE, config('metastyle.saas.default_plan_code'));
        $mailer = (string) config('mail.default');

        return view('livewire.sadmin.settings.index', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'dirty' => $this->trialDays !== $savedTrial || $this->defaultPlanCode !== $savedPlan,
            'hosts' => [
                'corporate' => $hosts->corporateHost(),
                'superadmin' => $hosts->superAdminHost(),
                'center' => '*.'.$hosts->baseDomain(),
                'scheme' => $hosts->scheme(),
            ],
            'languages' => collect($languages->supported())->map(fn (string $locale): array => [
                'locale' => $locale,
                'code' => $languages->shortLabel($locale),
                'name' => $languages->nativeName($locale),
                'direction' => $languages->direction($locale),
                'icon' => $languages->icon($locale),
            ])->all(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'canSecurity' => $user->hasPermission('platform.security.manage'),
            'mfaRequired' => $preferences->mfaRequired(),
            'mfaCounts' => [
                'enrolled' => PlatformUser::query()->where('is_active', true)->whereNull('archived_at')->whereNotNull('mfa_confirmed_at')->count(),
                'total' => PlatformUser::query()->where('is_active', true)->whereNull('archived_at')->count(),
            ],
            'events' => array_keys(PlatformPreferences::EVENTS),
            'mail' => [
                'mailer' => $mailer,
                'ready' => ! in_array($mailer, ['log', 'array'], true) && ($mailer !== 'smtp' || filled(config('mail.mailers.smtp.host'))),
                'from_name' => (string) config('mail.from.name'),
                'from_address' => (string) config('mail.from.address'),
                'queue' => (string) config('queue.default'),
            ],
        ]);
    }

    private function attempt(callable $action, string $field, ?string $success = null): void
    {
        try {
            $action();
        } catch (DomainException $exception) {
            $this->addError($field, $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', $success ?? __('platform_settings.saved'));
    }
}
