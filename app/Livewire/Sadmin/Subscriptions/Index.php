<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Subscriptions;

use App\Kernel\Audit\Actor;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasAdmin\Application\Actions\ManageSubscription;
use App\Modules\SaasAdmin\Application\Actions\ScheduleSubscriptionPlanChange;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every center's commercial standing in one list, with the changes a Super
 * Admin makes to it: change plan or billing cycle now, activate, move a trial
 * end, set the renewal date, or schedule a change for later.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    public const STATUSES = ['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired'];

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $plan = '';

    #[Url(except: '')]
    public string $cycle = '';

    #[Url(except: '')]
    public string $due = '';

    /** `plan:<id>`, `schedule:<id>`, `activate:<id>`, `trial:<id>`, `renewal:<id>`. */
    public ?string $panel = null;

    public ?int $targetPlanId = null;

    public string $targetCycle = 'monthly';

    public string $date = '';

    public string $effectiveAt = '';

    public string $reason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'plan', 'cycle', 'due'], true)) {
            $this->resetPage();
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, self::STATUSES, true) ? $status : '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'search', 'plan', 'cycle', 'due');
        $this->resetPage();
    }

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.subscription.manage');
        $this->closePanel();
        [$kind, $id] = array_pad(explode(':', $panel, 2), 2, '');
        $subscription = $this->subscription((int) $id);
        $this->targetPlanId = $subscription->plan_id;
        $this->targetCycle = $subscription->billing_period_snapshot === 'yearly' ? 'yearly' : 'monthly';
        $this->date = match ($kind) {
            'trial' => ($subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : now())->copy()->addDays(7)->format('Y-m-d'),
            'renewal' => ($subscription->current_period_end ?? now()->addMonth())->format('Y-m-d'),
            'activate' => now()->format('Y-m-d'),
            default => '',
        };
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'targetPlanId', 'targetCycle', 'date', 'effectiveAt', 'reason');
        $this->resetValidation();
    }

    public function apply(ManageSubscription $manage, ScheduleSubscriptionPlanChange $schedule): void
    {
        $user = $this->requirePlatformPermission('platform.subscription.manage');
        [$kind, $id] = array_pad(explode(':', (string) $this->panel, 2), 2, '');
        $subscription = $this->subscription((int) $id);
        $rules = ['reason' => ['required', 'string', 'min:5', 'max:1000']];
        $rules += match ($kind) {
            'plan' => ['targetPlanId' => ['required', 'integer'], 'targetCycle' => ['required', 'in:monthly,yearly']],
            'schedule' => ['targetPlanId' => ['required', 'integer'], 'effectiveAt' => ['required', 'date', 'after:now']],
            'activate' => ['targetCycle' => ['required', 'in:monthly,yearly'], 'date' => ['required', 'date']],
            'trial', 'renewal' => ['date' => ['required', 'date', 'after:today']],
            default => abort(404),
        };
        $this->validate($rules, [], [
            'reason' => __('sadmin_subscriptions.fields.reason'),
            'targetPlanId' => __('sadmin_subscriptions.fields.plan'),
            'date' => __('sadmin_subscriptions.fields.date'),
            'effectiveAt' => __('sadmin_subscriptions.fields.effective'),
        ]);
        $actor = Actor::platform($user);

        try {
            match ($kind) {
                'plan' => $manage->changePlan($subscription, Plan::query()->where('is_active', true)->findOrFail($this->targetPlanId), $this->targetCycle, $actor, $this->reason),
                'schedule' => $schedule($subscription, Plan::query()->where('is_active', true)->findOrFail($this->targetPlanId), Carbon::parse($this->effectiveAt), $actor, $this->reason),
                'activate' => $manage->activate($subscription, $this->targetCycle, Carbon::parse($this->date), $actor, $this->reason),
                'trial' => $manage->extendTrial($subscription, Carbon::parse($this->date)->endOfDay(), $actor, $this->reason),
                'renewal' => $manage->setRenewalDate($subscription, Carbon::parse($this->date)->endOfDay(), $actor, $this->reason),
            };
        } catch (DomainException $exception) {
            $this->addError('reason', $exception->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', __('sadmin_subscriptions.done.'.$kind));
    }

    public function render(PlatformCurrencies $currencies): mixed
    {
        $this->requirePlatformPermission('platform.subscription.manage');
        $locale = app()->getLocale();

        $subscriptions = $this->filtered()
            ->with(['tenant:id,name,slug,status', 'plan'])
            ->orderByRaw("CASE status WHEN 'past_due' THEN 0 WHEN 'trialing' THEN 1 WHEN 'active' THEN 2 ELSE 3 END")
            ->orderBy('current_period_end')
            ->orderBy('id')
            ->paginate(20);

        $pending = SubscriptionScheduledChange::query()
            ->whereIn('subscription_id', $subscriptions->getCollection()->pluck('id'))
            ->where('status', 'scheduled')
            ->get()
            ->keyBy('subscription_id');

        $counts = $this->filtered(withStatus: false)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $plans = Plan::query()->orderBy('sort_order')->orderBy('id')->get();
        $selected = null;
        if ($this->panel !== null) {
            $selected = Subscription::query()->with(['tenant:id,name', 'plan'])->find((int) explode(':', $this->panel, 2)[1]);
        }

        return view('livewire.sadmin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'pending' => $pending,
            'counts' => ['' => array_sum($counts)] + $counts,
            'plans' => $plans,
            'activePlans' => $plans->where('is_active', true)->values(),
            'planNames' => $plans->mapWithKeys(fn (Plan $plan): array => [$plan->id => $plan->name->get($locale)]),
            'prices' => $subscriptions->getCollection()->mapWithKeys(fn (Subscription $subscription): array => [
                $subscription->id => $subscription->price_minor_snapshot !== null && $subscription->currency_snapshot !== null
                    ? $currencies->format((int) $subscription->price_minor_snapshot, $subscription->currency_snapshot, $locale)
                    : null,
            ]),
            'selected' => $selected,
            'money' => $currencies,
            'hasFilters' => $this->status !== '' || $this->search !== '' || $this->plan !== '' || $this->cycle !== '' || $this->due !== '',
        ]);
    }

    private function subscription(int $id): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()->with('plan')->findOrFail($id);

        return $subscription;
    }

    /**
     * @return Builder<Subscription>
     */
    private function filtered(bool $withStatus = true): Builder
    {
        return Subscription::query()
            ->when($withStatus && $this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->plan !== '' && ctype_digit($this->plan), fn (Builder $q) => $q->where('plan_id', (int) $this->plan))
            ->when(in_array($this->cycle, ['monthly', 'yearly'], true), fn (Builder $q) => $q->where('billing_period_snapshot', $this->cycle))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('tenant', fn (Builder $tenant) => $tenant
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('slug', 'like', '%'.$this->search.'%')))
            ->when($this->due === 'trial', fn (Builder $q) => $q->where('status', 'trialing')
                ->whereBetween('trial_ends_at', [Carbon::now(), Carbon::now()->addDays(7)]))
            ->when($this->due === 'renewal', fn (Builder $q) => $q->whereIn('status', ['active', 'past_due'])
                ->whereBetween('current_period_end', [Carbon::now(), Carbon::now()->addDays(7)]));
    }
}
