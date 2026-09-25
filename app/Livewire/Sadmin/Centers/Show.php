<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Centers;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Money\Currency;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlanEntitlement;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionHistory;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\RegisteredCenterAddress;
use App\Kernel\Usage\Actions\SetTenantLimitOverride;
use App\Kernel\Usage\Allowances;
use App\Kernel\Usage\Models\TenantLimitOverride;
use App\Kernel\Usage\Models\TenantUsageProjection;
use App\Kernel\Usage\UsageCatalog;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\SaasAdmin\Application\Actions\ChangeTenantLifecycle;
use App\Modules\SaasAdmin\Application\Actions\ManageCenterAccount;
use App\Modules\SaasAdmin\Application\Actions\ManageSubscription;
use App\Modules\SaasAdmin\Application\Actions\ScheduleSubscriptionPlanChange;
use App\Modules\SaasAdmin\Application\Actions\SetTenantEntitlement;
use App\Modules\SaasAdmin\Application\Actions\UpdateCenterProfile;
use App\Modules\SaasAdmin\Application\CenterPeople;
use App\Modules\SaasBilling\Application\SaasAccountStatement;
use App\Modules\SaasBilling\Application\SaasBillingTotals;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use App\Modules\SaasBilling\Domain\StatementPeriod;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One center's control room: profile, people, subscription, capabilities,
 * usage, billing, addresses, lifecycle, support and audit.
 *
 * Every write goes through an Action that authorises, validates, locks and
 * audits; this component only decides which form is open. Only the active
 * tab's records are read.
 */
#[Layout('layouts.superadmin.app')]
final class Show extends Component
{
    use AuthorizesPlatform;

    public const TABS = ['overview', 'people', 'subscription', 'entitlements', 'usage', 'billing', 'domains', 'lifecycle', 'support', 'audit'];

    public string $tenantId = '';

    /** Account statement filters on the Billing tab — SaaS billing only, never the center's own finance. */
    public string $statementPreset = 'this_month';

    public string $statementFrom = '';

    public string $statementTo = '';

    public string $statementStatus = '';

    public string $statementMethod = '';

    public string $statementCurrency = '';

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /**
     * Which form is open: `edit`, `languages`, `address`, `lifecycle:<status>`,
     * `plan`, `activate`, `trial`, `renewal`, `schedule`, `entitlement:<key>`,
     * `entitlement-reset:<id>`, `usage:<code>`, `usage-reset:<id>`,
     * `person-block:<uuid>`, `person-unblock:<uuid>`, `person-link:<uuid>`.
     */
    #[Url(except: null)]
    public ?string $panel = null;

    /** For profile, languages, address, subscription and people forms. */
    public string $reason = '';

    /*
     * Separate reasons for the forms that had them first: a reason typed for
     * one operation must never be submitted as the reason for another.
     */
    public string $lifecycleReason = '';

    public string $entitlementReason = '';

    public string $scheduleReason = '';

    public string $usageReason = '';

    /** @var array{name: string, contact_name: string, contact_email: string, contact_phone: string, timezone: string, currency: string} */
    public array $profile = ['name' => '', 'contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'timezone' => '', 'currency' => ''];

    /** @var array<int, string> */
    public array $locales = [];

    public string $primaryLocale = '';

    public string $slug = '';

    public string $confirmName = '';

    public ?int $targetPlanId = null;

    public string $cycle = 'monthly';

    public string $effectiveAt = '';

    public string $startsAt = '';

    public string $trialUntil = '';

    public string $renewalDate = '';

    public string $entitlement = '';

    public string $mode = 'grant';

    public string $expiresAt = '';

    public string $usageResource = '';

    public string $usageAllowance = '';

    public bool $usageUnlimited = false;

    public bool $usageEnforce = false;

    public function mount(string $tenant): void
    {
        $this->tenantId = $tenant;
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
        if ($this->panel !== null) {
            $panel = $this->panel;
            $this->panel = null;
            // A deep link names a drawer. An unknown one, or one this person
            // may not open, leaves the page open without it.
            try {
                $this->openPanel($panel);
            } catch (HttpException) {
                $this->panel = null;
            }
        }
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
        $this->closePanel();
    }

    public function openPanel(string $panel): void
    {
        $this->closePanel();
        [$kind, $subject] = array_pad(explode(':', $panel, 2), 2, '');

        match ($kind) {
            'edit', 'languages', 'address', 'lifecycle', 'person-block', 'person-unblock', 'person-link' => $this->requirePlatformPermission('platform.center.manage'),
            'plan', 'activate', 'trial', 'renewal', 'schedule' => $this->requirePlatformPermission('platform.subscription.manage'),
            'entitlement', 'entitlement-reset' => $this->requirePlatformPermission('platform.entitlement.manage'),
            'usage', 'usage-reset' => $this->requirePlatformPermission('platform.usage.manage'),
            default => abort(404),
        };

        $tenant = $this->tenant();
        $subscription = $this->subscription();

        switch ($kind) {
            case 'lifecycle':
                $this->reset('lifecycleReason', 'confirmName');
                break;
            case 'edit':
                $this->profile = [
                    'name' => $tenant->name,
                    'contact_name' => (string) $tenant->contact_name,
                    'contact_email' => (string) $tenant->contact_email,
                    'contact_phone' => (string) $tenant->contact_phone,
                    'timezone' => (string) $tenant->timezone,
                    'currency' => (string) $tenant->currency,
                ];
                break;
            case 'languages':
                $current = app(UpdateCenterProfile::class)->languages($tenant);
                $this->locales = $current['enabled'];
                $this->primaryLocale = $current['primary'];
                break;
            case 'address':
                $this->slug = (string) $tenant->slug;
                break;
            case 'plan':
                $this->targetPlanId = $subscription?->plan_id;
                $this->cycle = $subscription?->billing_period_snapshot === 'yearly' ? 'yearly' : 'monthly';
                break;
            case 'activate':
                $this->cycle = $subscription?->billing_period_snapshot === 'yearly' ? 'yearly' : 'monthly';
                $this->startsAt = now()->format('Y-m-d');
                break;
            case 'trial':
                $this->trialUntil = ($subscription?->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now())->copy()->addDays(7)->format('Y-m-d');
                break;
            case 'renewal':
                $this->renewalDate = ($subscription->current_period_end ?? now()->addMonth())->format('Y-m-d');
                break;
            case 'entitlement':
                $this->entitlement = $subject;
                $this->mode = app(Entitlements::class)->for($this->tenantId)->owns($subject) ? 'revoke' : 'grant';
                break;
            case 'usage':
                $this->usageResource = $subject;
                $allowance = app(Allowances::class)->resolve($this->tenantId, $subject);
                $this->usageUnlimited = $allowance->isUnlimited();
                $this->usageAllowance = $allowance->allowance === null ? '' : (string) $allowance->allowance;
                break;
        }

        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('reason', 'confirmName', 'entitlement', 'mode', 'expiresAt', 'targetPlanId', 'effectiveAt', 'startsAt', 'trialUntil', 'renewalDate',
            'usageResource', 'usageAllowance', 'usageUnlimited', 'usageEnforce', 'slug', 'locales', 'primaryLocale',
            'entitlementReason', 'scheduleReason', 'usageReason');
        $this->resetValidation();
    }

    public function saveProfile(UpdateCenterProfile $update): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $data = $this->validate([
            'profile.name' => ['required', 'string', 'max:190'],
            'profile.contact_name' => ['nullable', 'string', 'max:190'],
            'profile.contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'profile.contact_phone' => ['nullable', 'string', 'max:48'],
            'profile.timezone' => ['nullable', 'string', 'timezone:all'],
            'profile.currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ])['profile'];

        $this->attempt(fn () => $update->update($this->tenant(), [
            'name' => $data['name'],
            'contact_name' => $data['contact_name'] ?: null,
            'contact_email' => $data['contact_email'] ?: null,
            'contact_phone' => $data['contact_phone'] ?: null,
            'timezone' => $data['timezone'] ?: null,
            'currency' => $data['currency'] ?: null,
        ], Actor::platform($user), $this->reason), __('sadmin_centers.saved.profile'));
    }

    public function saveLanguages(UpdateCenterProfile $update): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $this->validate([
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string'],
            'primaryLocale' => ['required', 'string', 'in:'.implode(',', $this->locales)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $this->attempt(fn () => $update->setLanguages($this->tenant(), array_values($this->locales), $this->primaryLocale, Actor::platform($user), $this->reason), __('sadmin_centers.saved.languages'));
    }

    public function saveAddress(UpdateCenterProfile $update): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $this->validate([
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $this->attempt(fn () => $update->changeAddress($this->tenant(), $this->slug, Actor::platform($user), $this->reason), __('sadmin_centers.saved.address'));
    }

    public function changeStatus(string $target, ChangeTenantLifecycle $change): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $status = TenantStatus::tryFrom($target);
        if (! $status instanceof TenantStatus) {
            return;
        }
        $rules = ['lifecycleReason' => ['required', 'string', 'min:5', 'max:1000']];
        if ($status === TenantStatus::Archived) {
            $rules['confirmName'] = ['required', 'string', 'in:'.$this->tenant()->name];
        }
        $this->validate($rules, ['confirmName.in' => __('sadmin_centers.errors.confirm_name')]);
        $this->attempt(fn () => $change($this->tenantId, $status, Actor::platform($user), $this->lifecycleReason), __('sadmin_centers.saved.lifecycle'), 'lifecycleReason');
        if (! $this->getErrorBag()->has('lifecycleReason')) {
            $this->reset('lifecycleReason');
        }
    }

    public function changePlan(ManageSubscription $manage): void
    {
        $user = $this->requirePlatformPermission('platform.subscription.manage');
        $this->validate([
            'targetPlanId' => ['required', 'integer'],
            'cycle' => ['required', 'in:monthly,yearly'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $plan = Plan::query()->where('is_active', true)->findOrFail($this->targetPlanId);
        $this->attempt(fn () => $manage->changePlan($this->requireSubscription(), $plan, $this->cycle, Actor::platform($user), $this->reason), __('sadmin_centers.saved.plan'));
    }

    public function activate(ManageSubscription $manage): void
    {
        $user = $this->requirePlatformPermission('platform.subscription.manage');
        $this->validate([
            'cycle' => ['required', 'in:monthly,yearly'],
            'startsAt' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $this->attempt(fn () => $manage->activate($this->requireSubscription(), $this->cycle, Carbon::parse($this->startsAt), Actor::platform($user), $this->reason), __('sadmin_centers.saved.activated'));
    }

    public function extendTrial(ManageSubscription $manage): void
    {
        $user = $this->requirePlatformPermission('platform.subscription.manage');
        $this->validate([
            'trialUntil' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $this->attempt(fn () => $manage->extendTrial($this->requireSubscription(), Carbon::parse($this->trialUntil)->endOfDay(), Actor::platform($user), $this->reason), __('sadmin_centers.saved.trial'));
    }

    public function setRenewal(ManageSubscription $manage): void
    {
        $user = $this->requirePlatformPermission('platform.subscription.manage');
        $this->validate([
            'renewalDate' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $this->attempt(fn () => $manage->setRenewalDate($this->requireSubscription(), Carbon::parse($this->renewalDate)->endOfDay(), Actor::platform($user), $this->reason), __('sadmin_centers.saved.renewal'));
    }

    public function scheduleChange(ScheduleSubscriptionPlanChange $schedule): void
    {
        $user = $this->requirePlatformPermission('platform.subscription.manage');
        $data = $this->validate([
            'targetPlanId' => ['required', 'integer'],
            'effectiveAt' => ['required', 'date', 'after:now'],
            'scheduleReason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $plan = Plan::query()->where('is_active', true)->findOrFail($data['targetPlanId']);
        $this->attempt(fn () => $schedule($this->requireSubscription(), $plan, Carbon::parse($data['effectiveAt']), Actor::platform($user), $this->scheduleReason), __('sadmin_centers.saved.scheduled'), 'scheduleReason');
    }

    public function setEntitlement(SetTenantEntitlement $set): void
    {
        $user = $this->requirePlatformPermission('platform.entitlement.manage');
        $data = $this->validate([
            'entitlement' => ['required', 'string'],
            'mode' => ['required', 'in:grant,revoke'],
            'entitlementReason' => ['required', 'string', 'min:5', 'max:1000'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ]);
        $this->attempt(fn () => $set($this->tenantId, $data['entitlement'], OverrideMode::from($data['mode']), Actor::platform($user), $this->entitlementReason, $data['expiresAt'] !== '' && $data['expiresAt'] !== null ? Carbon::parse($data['expiresAt']) : null), __('sadmin_centers.entitlement_saved'), 'entitlementReason');
    }

    public function clearEntitlement(int $overrideId, SetTenantEntitlement $set): void
    {
        $user = $this->requirePlatformPermission('platform.entitlement.manage');
        $this->validate(['entitlementReason' => ['required', 'string', 'min:5', 'max:1000']]);
        $override = TenantEntitlementOverride::query()->where('tenant_id', $this->tenantId)->findOrFail($overrideId);
        $this->attempt(fn () => $set->clear($this->tenantId, $override->entitlement, Actor::platform($user), $this->entitlementReason), __('sadmin_centers.entitlement_cleared'), 'entitlementReason');
    }

    public function saveAllowance(SetTenantLimitOverride $set): void
    {
        $user = $this->requirePlatformPermission('platform.usage.manage');
        $data = $this->validate([
            'usageResource' => ['required', 'string', 'in:'.implode(',', app(UsageCatalog::class)->codes())],
            'usageAllowance' => [$this->usageUnlimited ? 'nullable' : 'required', 'nullable', 'integer', 'min:0'],
            'usageUnlimited' => ['boolean'],
            'usageEnforce' => ['boolean'],
            'usageReason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $this->attempt(fn () => $set($this->tenantId, $data['usageResource'], $data['usageUnlimited'] ? null : (int) $data['usageAllowance'], Actor::platform($user), $this->usageReason, (bool) $data['usageEnforce']), __('sadmin_centers.usage_saved'), 'usageReason');
    }

    public function clearAllowance(int $overrideId, SetTenantLimitOverride $set): void
    {
        $user = $this->requirePlatformPermission('platform.usage.manage');
        $this->validate(['usageReason' => ['required', 'string', 'min:5', 'max:1000']]);
        $override = TenantLimitOverride::query()->where('tenant_id', $this->tenantId)->findOrFail($overrideId);
        $this->attempt(fn () => $set->clear($this->tenantId, $override->resource, Actor::platform($user), $this->usageReason), __('sadmin_centers.usage_cleared'), 'usageReason');
    }

    public function setPersonActive(string $uuid, bool $active, ManageCenterAccount $accounts): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->attempt(fn () => $accounts->setActive($this->tenant(), $uuid, $active, Actor::platform($user), $this->reason), $active ? __('sadmin_centers.saved.person_reactivated') : __('sadmin_centers.saved.person_blocked'));
    }

    public function sendAccessLink(string $uuid, ManageCenterAccount $accounts): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $this->attempt(fn () => $accounts->sendAccessLink($this->tenant(), $uuid, Actor::platform($user)), __('sadmin_centers.saved.link_sent'));
    }

    public function render(RegisteredCenterAddress $addresses, PlatformCurrencies $currencies, LanguageRegistry $languages): mixed
    {
        $user = $this->requirePlatformPermission('platform.center.view');
        $tenant = $this->tenant();
        $subscription = $this->subscription();
        $locale = app()->getLocale();

        return view('livewire.sadmin.centers.show', [
            'tenant' => $tenant,
            'address' => $addresses->resolve($tenant),
            'subscription' => $subscription,
            'commercial' => $this->commercial($subscription, $currencies, $locale),
            'can' => [
                'manage' => $user->hasPermission('platform.center.manage'),
                'subscription' => $user->hasPermission('platform.subscription.manage'),
                'entitlements' => $user->hasPermission('platform.entitlement.manage'),
                'usage' => $user->hasPermission('platform.usage.manage'),
                'billing' => $user->hasPermission('platform.billing.manage'),
                'support' => $user->hasPermission('platform.support.view'),
                'audit' => $user->hasPermission('platform.audit.view'),
                'center_users' => $user->hasPermission('platform.center_user.view'),
            ],
            'transitions' => array_map(static fn (TenantStatus $status): string => $status->value, ChangeTenantLifecycle::targetsFrom((string) $tenant->status)),
            'currencyOptions' => $currencies->centerCodes(),
            'currencyLocked' => $this->panel === 'edit' ? app(UpdateCenterProfile::class)->currencyLocked($tenant) : false,
            'defaultCurrency' => Currency::default()->value,
            'timezones' => DateTimeZone::listIdentifiers(),
            'supportedLocales' => collect($languages->supported())->mapWithKeys(fn (string $code): array => [$code => $languages->nativeName($code)])->all(),
            'plans' => in_array($this->panel, ['plan', 'schedule'], true) || $this->tab === 'subscription' ? Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() : collect(),
            'data' => $this->tabData($tenant, $subscription, $currencies, $locale),
        ]);
    }

    /** Runs an action, turning a refused business rule into a message. */
    private function attempt(callable $action, string $success, string $field = 'reason'): void
    {
        try {
            $action();
        } catch (DomainException $exception) {
            $this->addError($field, $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', $success);
    }

    private function tenant(): TenantModel
    {
        /** @var TenantModel $tenant */
        $tenant = TenantModel::query()->with('domains')->findOrFail($this->tenantId);

        return $tenant;
    }

    private function subscription(): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->with('plan')->where('tenant_id', $this->tenantId)->first();

        return $subscription;
    }

    private function requireSubscription(): Subscription
    {
        return $this->subscription() ?? throw new DomainException(__('sadmin_subscriptions.errors.missing'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commercial(?Subscription $subscription, PlatformCurrencies $currencies, string $locale): ?array
    {
        if (! $subscription instanceof Subscription) {
            return null;
        }

        $status = $subscription->effectiveStatus();
        $snapshot = $subscription->plan_name_snapshot;
        $name = $subscription->plan?->name?->get($locale);
        if (($name === null || $name === '') && is_array($snapshot)) {
            $name = $snapshot[$locale] ?? $snapshot['en'] ?? reset($snapshot);
        }

        return [
            'plan' => is_string($name) && $name !== '' ? $name : '—',
            'status' => $status->value,
            'price' => $subscription->price_minor_snapshot !== null && $subscription->currency_snapshot !== null
                ? $currencies->format((int) $subscription->price_minor_snapshot, $subscription->currency_snapshot, $locale)
                : null,
            'currency' => $subscription->currency_snapshot,
            'cycle' => $subscription->billing_period_snapshot,
            'trial_days' => $status->isTrial() ? $subscription->trialDaysLeft() : null,
            'trial_ends' => $subscription->trial_ends_at,
            'period_end' => $subscription->current_period_end,
            'canActivate' => in_array($status->value, ['trialing', 'expired', 'past_due'], true),
            'isTrial' => in_array($status->value, ['trialing', 'expired'], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tabData(TenantModel $tenant, ?Subscription $subscription, PlatformCurrencies $currencies, string $locale): array
    {
        return match ($this->tab) {
            'people' => ['people' => app(CenterPeople::class)->list($tenant)],
            'subscription' => [
                'history' => $subscription === null ? collect() : SubscriptionHistory::query()->with(['fromPlan', 'toPlan'])
                    ->where('subscription_id', $subscription->id)->latest('occurred_at')->limit(40)->get(),
                'scheduled' => $subscription === null ? collect() : SubscriptionScheduledChange::query()
                    ->where('subscription_id', $subscription->id)->latest('effective_at')->limit(20)->get(),
                'planNames' => Plan::query()->pluck('name', 'id'),
            ],
            'entitlements' => $this->entitlementRows($tenant, $subscription),
            'usage' => $this->usageRows($tenant),
            'billing' => $this->billingData($tenant, $currencies, $locale),
            'domains' => ['domains' => $tenant->domains->sortByDesc('is_primary')->values()],
            'lifecycle' => [
                'lifecycle' => DB::connection('control')->table('tenant_lifecycle_history')->where('tenant_id', $tenant->id)->latest('occurred_at')->limit(40)->get(),
            ],
            'support' => [
                'tickets' => SupportTicket::query()->where('tenant_id', $tenant->id)->latest('last_activity_at')->limit(25)->get(),
            ],
            'audit' => [
                'audit' => PlatformAuditLog::query()->where('tenant_id', $tenant->id)->latest('occurred_at')->limit(40)->get(),
            ],
            default => [
                'people' => app(CenterPeople::class)->summary($tenant),
                'languages' => app(UpdateCenterProfile::class)->languages($tenant),
                'openTickets' => SupportTicket::query()->where('tenant_id', $tenant->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
                'outstanding' => $this->outstanding($tenant, $currencies, $locale),
                'lastChange' => DB::connection('control')->table('tenant_lifecycle_history')->where('tenant_id', $tenant->id)->latest('occurred_at')->first(),
            ],
        };
    }

    /**
     * The Billing tab: recent invoices and settlements, and the account
     * statement for the chosen period, grouped per currency (never summed
     * across currencies). Reads SaaS billing only.
     *
     * @return array<string, mixed>
     */
    private function billingData(TenantModel $tenant, PlatformCurrencies $currencies, string $locale): array
    {
        $period = StatementPeriod::resolve($this->statementPreset, $this->statementFrom, $this->statementTo, app(PlatformPreferences::class)->general()['default_timezone']);
        $filters = [
            'status' => in_array($this->statementStatus, SaasAccountStatement::STATUSES, true) ? $this->statementStatus : null,
            'method' => in_array($this->statementMethod, SaasAccountStatement::METHODS, true) ? $this->statementMethod : null,
            'currency' => preg_match('/^[A-Z]{3}$/', $this->statementCurrency) === 1 ? $this->statementCurrency : null,
        ];
        $statement = app(SaasAccountStatement::class)->build((string) $tenant->id, $period->from, $period->to, $filters);
        $money = static fn (?int $minor, string $currency): ?string => $minor === null ? null : $currencies->format($minor, $currency, $locale);

        return [
            'invoices' => SaasInvoice::query()->where('tenant_id', $tenant->id)->latest('issued_at')->limit(12)->get(),
            'invoiceCount' => SaasInvoice::query()->where('tenant_id', $tenant->id)->count(),
            'payments' => SaasPayment::query()->where('tenant_id', $tenant->id)->latest('received_at')->limit(8)->get(),
            'paymentInvoices' => SaasInvoice::query()->where('tenant_id', $tenant->id)->pluck('number', 'id'),
            'outstanding' => $this->outstanding($tenant, $currencies, $locale),
            'statementCurrencies' => SaasInvoice::query()->where('tenant_id', $tenant->id)->distinct()->orderBy('currency')->pluck('currency')->map(static fn ($code): string => mb_strtoupper((string) $code))->all(),
            'statement' => [
                'period' => $period,
                'filtered' => $statement['filtered'],
                'query' => array_filter(['tenant' => $tenant->id] + $period->query() + $filters),
                'groups' => array_map(static fn (array $group): array => [
                    'currency' => $group['currency'],
                    'opening' => $money($group['opening'], $group['currency']),
                    'closing' => $money($group['closing'], $group['currency']),
                    'invoiced' => $money($group['totals']['invoiced'], $group['currency']),
                    'paid' => $money($group['totals']['paid'], $group['currency']),
                    'reversed' => $money($group['totals']['reversed'], $group['currency']),
                    'voided' => $money($group['totals']['voided'], $group['currency']),
                    'lines' => count($group['lines']),
                ], $statement['currencies']),
            ],
        ];
    }

    /** @return list<array{amount: string, invoices: int}> */
    private function outstanding(TenantModel $tenant, PlatformCurrencies $currencies, string $locale): array
    {
        return array_map(
            static fn (array $due): array => ['amount' => $currencies->format($due['minor'], $due['currency'], $locale), 'invoices' => $due['invoices']],
            app(SaasBillingTotals::class)->outstanding((string) $tenant->id),
        );
    }

    /**
     * Every capability in the catalog, with where its state comes from.
     *
     * @return array<string, mixed>
     */
    private function entitlementRows(TenantModel $tenant, ?Subscription $subscription): array
    {
        $catalog = app(EntitlementCatalog::class);
        $effective = app(Entitlements::class)->for($tenant->id);
        $inPlan = $subscription === null ? [] : PlanEntitlement::query()->where('plan_id', $subscription->plan_id)->pluck('entitlement')->all();
        $overrides = TenantEntitlementOverride::query()->where('tenant_id', $tenant->id)->get()->keyBy('entitlement');

        $rows = [];
        foreach ($catalog->keys() as $key) {
            $rows[$catalog->category($key)][] = [
                'key' => $key,
                'requires' => $catalog->requires($key),
                'in_plan' => in_array($key, $inPlan, true),
                'override' => $overrides->get($key),
                'owned' => $effective->owns($key),
            ];
        }

        return ['groups' => $rows, 'access' => $effective->accessLevel->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function usageRows(TenantModel $tenant): array
    {
        $allowances = app(Allowances::class);
        $projections = TenantUsageProjection::query()->where('tenant_id', $tenant->id)->get()->keyBy('resource');
        $overrides = TenantLimitOverride::query()->where('tenant_id', $tenant->id)->get()->keyBy('resource');

        $catalog = app(UsageCatalog::class);
        $rows = [];
        foreach ($catalog->codes() as $code) {
            $allowance = $allowances->resolve($tenant->id, $code);
            $projection = $projections->get($code);
            $rows[] = [
                'code' => $code,
                'enforced' => $catalog->isEnforced($code),
                'used' => $projection?->used,
                'allowance' => $allowance->allowance,
                'source' => $allowance->source,
                'status' => $projection?->status->value,
                'percent' => $projection?->percent,
                'period_end' => $projection?->period_end,
                'override' => $overrides->get($code),
            ];
        }

        return ['rows' => $rows];
    }
}
