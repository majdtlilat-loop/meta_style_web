<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Plans;

use App\Kernel\Audit\Actor;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Usage\Models\PlanLimit;
use App\Kernel\Usage\UsageCatalog;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasAdmin\Application\Actions\ManagePlan;
use DomainException;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Plans: one plan, sold monthly and/or yearly, in one currency, with the
 * capabilities and limits it includes. A price change never touches what a
 * subscribed center already agreed to pay (subscription snapshot).
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;

    #[Url(except: 'plans')]
    public string $view = 'plans';

    public bool $editorOpen = false;

    public ?int $editingPlanId = null;

    public string $code = '';

    /** @var array<string,string> */
    public array $name = ['en' => '', 'ar' => '', 'ckb' => ''];

    /** @var array<string,string> */
    public array $description = ['en' => '', 'ar' => '', 'ckb' => ''];

    public string $currency = 'IQD';

    public bool $offersMonthly = true;

    public bool $offersYearly = false;

    /**
     * What the person typed, per cycle, in major units ("25000", "19.99").
     *
     * @var array{monthly: string, yearly: string}
     */
    public array $prices = ['monthly' => '', 'yearly' => ''];

    /** @var array{monthly: int|null, yearly: int|null} */
    public array $minor = ['monthly' => null, 'yearly' => null];

    /*
     * The single-price editor contract, kept as an alias of the cycle named by
     * `billingPeriod`: typing `price` (or setting `priceMinor`) prices that
     * cycle.
     */
    public int $priceMinor = 0;

    public string $price = '';

    public string $billingPeriod = 'monthly';

    public string $trialDays = '';

    public bool $isPublic = true;

    public bool $isFeatured = false;

    public int $sortOrder = 0;

    public string $reason = '';

    /** @var list<string> */
    public array $selectedEntitlements = [];

    /**
     * Per enforced resource: '' = platform default, 'unlimited', or a number.
     *
     * @var array<string, string>
     */
    public array $limits = [];

    public function create(UsageCatalog $usage): void
    {
        $this->requirePlatformPermission('platform.plan.manage');
        $this->resetEditor();
        $this->currency = app(PlatformCurrencies::class)->defaultCode();
        $this->sortOrder = (int) Plan::query()->max('sort_order') + 1;
        $this->limits = array_fill_keys($this->enforced($usage), '');
        $this->editorOpen = true;
    }

    public function edit(int $planId, UsageCatalog $usage): void
    {
        $this->requirePlatformPermission('platform.plan.manage');
        $this->fill_($this->plan($planId), $usage);
        $this->editorOpen = true;
    }

    /** A new plan pre-filled from an existing one. */
    public function duplicate(int $planId, UsageCatalog $usage): void
    {
        $this->requirePlatformPermission('platform.plan.manage');
        $source = $this->plan($planId);
        $this->fill_($source, $usage);
        $this->editingPlanId = null;
        $this->code = $source->code.'_copy';
        foreach ($this->name as $locale => $value) {
            $this->name[$locale] = $value === '' ? '' : $value.' (2)';
        }
        $this->isPublic = false;
        $this->sortOrder = (int) Plan::query()->max('sort_order') + 1;
        $this->editorOpen = true;
    }

    public function updatedPrices(mixed $value, string $cycle): void
    {
        if (! in_array($cycle, Plan::CYCLES, true)) {
            return;
        }
        $this->resetValidation('prices.'.$cycle);
        $this->minor[$cycle] = $this->parse((string) $value, 'prices.'.$cycle);
    }

    public function updatedPrice(): void
    {
        $this->resetValidation('price');
        $minor = $this->parse($this->price, 'price');
        if ($minor !== null) {
            $this->priceMinor = $minor;
            $this->updatedPriceMinor();
        }
    }

    public function updatedPriceMinor(): void
    {
        $cycle = $this->billingPeriod === 'yearly' ? 'yearly' : 'monthly';
        $this->minor[$cycle] = $this->priceMinor;
        $this->prices[$cycle] = app(PlatformCurrencies::class)->major($this->priceMinor, $this->currency);
        $this->{$cycle === 'yearly' ? 'offersYearly' : 'offersMonthly'} = true;
    }

    public function updatedCurrency(): void
    {
        $this->currency = mb_strtoupper(trim($this->currency));
        if ($this->price !== '') {
            $this->updatedPrice();
        }
        foreach (Plan::CYCLES as $cycle) {
            if ($this->prices[$cycle] !== '') {
                $this->updatedPrices($this->prices[$cycle], $cycle);
            }
        }
    }

    public function closeEditor(): void
    {
        $this->resetEditor();
    }

    public function save(ManagePlan $manage, EntitlementCatalog $catalog, UsageCatalog $usage): void
    {
        $user = $this->requirePlatformPermission('platform.plan.manage');
        foreach (Plan::CYCLES as $cycle) {
            if ($this->getErrorBag()->has('prices.'.$cycle)) {
                return;
            }
        }
        $data = $this->validate([
            'code' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]+$/', Rule::unique('control.plans', 'code')->ignore($this->editingPlanId)],
            'name.en' => ['required', 'string', 'max:190'], 'name.ar' => ['nullable', 'string', 'max:190'], 'name.ckb' => ['nullable', 'string', 'max:190'],
            'description.*' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', 'string', 'size:3'],
            'minor.monthly' => [$this->offersMonthly ? 'required' : 'nullable', 'nullable', 'integer', 'min:0'],
            'minor.yearly' => [$this->offersYearly ? 'required' : 'nullable', 'nullable', 'integer', 'min:0'],
            'trialDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'isPublic' => ['boolean'], 'isFeatured' => ['boolean'], 'sortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'], 'selectedEntitlements' => ['array'],
            'selectedEntitlements.*' => ['string', Rule::in($catalog->keys())],
            'limits' => ['array'],
            'limits.*' => ['nullable', 'regex:/^(unlimited|\d{1,9})?$/'],
        ], [], [
            'minor.monthly' => __('sadmin_plans.fields.monthly_price'),
            'minor.yearly' => __('sadmin_plans.fields.yearly_price'),
            'name.en' => __('sadmin_plans.fields.name'),
            'code' => __('sadmin_plans.fields.code'),
            'reason' => __('sadmin_plans.fields.reason'),
        ]);

        if (! $this->offersMonthly && ! $this->offersYearly) {
            $this->addError('minor.monthly', __('sadmin_plans.errors.no_cycle'));

            return;
        }

        $limits = [];
        foreach ($this->enforced($usage) as $resource) {
            $value = trim((string) ($this->limits[$resource] ?? ''));
            $limits[$resource] = $value === '' ? 'default' : ($value === 'unlimited' ? null : (int) $value);
        }

        try {
            $plan = $manage->save(
                $this->editingPlanId === null ? null : $this->plan($this->editingPlanId),
                $data['code'], $this->name, $this->description,
                $this->offersMonthly ? $this->minor['monthly'] : null,
                $this->offersYearly ? $this->minor['yearly'] : null,
                $data['currency'],
                $data['trialDays'] === '' || $data['trialDays'] === null ? null : (int) $data['trialDays'],
                $data['isPublic'], $data['isFeatured'], $data['sortOrder'],
                array_values(array_unique($data['selectedEntitlements'])), Actor::platform($user), $data['reason'],
            );
            $manage->setLimits($plan, $limits, Actor::platform($user), $data['reason']);
        } catch (DomainException $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->resetEditor();
        session()->flash('notice', __('sadmin_plans.saved'));
    }

    public function setActive(bool $active, ManagePlan $manage): void
    {
        $user = $this->requirePlatformPermission('platform.plan.manage');
        $this->validate(['editingPlanId' => ['required', 'integer', 'exists:control.plans,id'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $manage->setActive($this->plan((int) $this->editingPlanId), $active, Actor::platform($user), $this->reason);
        } catch (DomainException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }
        $this->resetEditor();
        session()->flash('notice', $active ? __('sadmin_plans.restored') : __('sadmin_plans.archived'));
    }

    public function render(EntitlementCatalog $catalog, PlatformCurrencies $currencies, UsageCatalog $usage): mixed
    {
        $this->requirePlatformPermission('platform.plan.manage');
        $plans = Plan::query()->with('entitlements')->withCount('entitlements')->orderBy('sort_order')->orderBy('id')->get();

        return view('livewire.sadmin.plans.index', [
            'plans' => $plans,
            'catalog' => $catalog,
            'grouped' => collect($catalog->keys())->groupBy(fn (string $key): string => $catalog->category($key))->all(),
            'subscribers' => Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])
                ->selectRaw('plan_id, COUNT(*) AS aggregate')->groupBy('plan_id')->pluck('aggregate', 'plan_id'),
            'cycles' => Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])
                ->selectRaw('plan_id, billing_period_snapshot AS cycle, COUNT(*) AS aggregate')->groupBy('plan_id', 'billing_period_snapshot')->get()
                ->groupBy('plan_id')->map(fn ($rows) => $rows->pluck('aggregate', 'cycle')->map(fn ($v) => (int) $v)->all())->all(),
            'planLimits' => PlanLimit::query()->get()->groupBy('plan_id')->map(fn ($rows) => $rows->keyBy('resource'))->all(),
            'enforced' => $this->enforced($usage),
            'usageCatalog' => $usage,
            'defaultPlan' => PlatformSetting::get(PlatformSetting::DEFAULT_PLAN_CODE),
            'currencies' => $currencies->enabledCodes(),
            'money' => $currencies,
            'editing' => $this->editingPlanId !== null ? $plans->firstWhere('id', $this->editingPlanId) : null,
        ]);
    }

    private function fill_(Plan $plan, UsageCatalog $usage): void
    {
        $currencies = app(PlatformCurrencies::class);
        $this->resetEditor();
        $this->editingPlanId = $plan->id;
        $this->code = $plan->code;
        $this->name = $plan->name->all() + ['en' => '', 'ar' => '', 'ckb' => ''];
        $this->description = ($plan->description?->all() ?? []) + ['en' => '', 'ar' => '', 'ckb' => ''];
        $this->currency = $plan->currency;
        foreach (Plan::CYCLES as $cycle) {
            $minor = $plan->priceFor($cycle);
            $this->minor[$cycle] = $minor;
            $this->prices[$cycle] = $minor === null ? '' : $currencies->major($minor, $plan->currency);
        }
        $this->offersMonthly = $this->minor['monthly'] !== null;
        $this->offersYearly = $this->minor['yearly'] !== null;
        $this->priceMinor = $plan->price_minor;
        $this->billingPeriod = $plan->billing_period;
        $this->trialDays = $plan->trial_days === null ? '' : (string) $plan->trial_days;
        $this->isPublic = $plan->is_public;
        $this->isFeatured = $plan->is_featured;
        $this->sortOrder = $plan->sort_order;
        $this->selectedEntitlements = $plan->entitlementCodes();
        $stored = PlanLimit::query()->where('plan_id', $plan->id)->get()->keyBy('resource');
        foreach ($this->enforced($usage) as $resource) {
            $row = $stored->get($resource);
            $this->limits[$resource] = $row === null ? '' : ($row->allowance === null ? 'unlimited' : (string) $row->allowance);
        }
    }

    private function parse(string $typed, string $field): ?int
    {
        if (trim($typed) === '') {
            return null;
        }
        try {
            return app(PlatformCurrencies::class)->parse($typed, $this->currency);
        } catch (InvalidArgumentException $exception) {
            $this->addError($field, $exception->getMessage());

            return null;
        }
    }

    /** @return list<string> */
    private function enforced(UsageCatalog $usage): array
    {
        return array_values(array_filter($usage->codes(), fn (string $code): bool => $usage->isEnforced($code)));
    }

    private function plan(int $id): Plan
    {
        /** @var Plan $plan */
        $plan = Plan::query()->with('entitlements')->findOrFail($id);

        return $plan;
    }

    private function resetEditor(): void
    {
        $this->reset('editorOpen', 'editingPlanId', 'code', 'name', 'description', 'currency', 'offersMonthly', 'offersYearly', 'prices', 'minor',
            'priceMinor', 'price', 'billingPeriod', 'trialDays', 'isPublic', 'isFeatured', 'sortOrder', 'reason', 'selectedEntitlements', 'limits');
        $this->resetValidation();
    }
}
