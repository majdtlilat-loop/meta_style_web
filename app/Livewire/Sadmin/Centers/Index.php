<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Centers;

use App\Kernel\Audit\Actor;
use App\Kernel\Money\Currency;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\RegisteredCenterAddress;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The center registry.
 *
 * Commercial filters (plan, subscription, trial) read the control-plane
 * subscription row through a sub-select, so the tenants query stays one
 * paginated statement and a center without a subscription is still listed.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    private const TRIAL_WARNING_DAYS = 7;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'provisioning', except: '')]
    public string $provisioningStatus = '';

    #[Url(except: '')]
    public string $plan = '';

    #[Url(except: '')]
    public string $subscription = '';

    #[Url(except: '')]
    public string $trial = '';

    #[Url(except: '')]
    public string $cycle = '';

    #[Url(except: '')]
    public string $currency = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public int $perPage = 20;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'provisioningStatus', 'plan', 'subscription', 'trial', 'cycle', 'currency'], true)) {
            $this->resetPage();
        }
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [20, 50, 100], true)) {
            $this->perPage = 20;
        }

        $this->resetPage();
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['', 'active', 'suspended', 'cancelled', 'provisioning', 'failed', 'archived'], true) ? $status : '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'provisioningStatus', 'plan', 'subscription', 'trial', 'cycle', 'currency');
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'status', 'provisioning_status', 'created_at'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = $column === 'created_at' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    /** Re-queues a center whose creation failed (same idempotent pipeline). */
    public function retryCreation(string $uuid, RegistrationService $registrations): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $registration = Registration::query()->where('uuid', $uuid)->firstOrFail();
        $registrations->retry($registration, Actor::platform($user))
            ? session()->flash('notice', __('sadmin_centers.creating.retried'))
            : session()->flash('notice', __('sadmin_centers.creating.not_retryable'));
    }

    public function dismissCreation(string $uuid, RegistrationService $registrations): void
    {
        $user = $this->requirePlatformPermission('platform.center.manage');
        $registration = Registration::query()->where('uuid', $uuid)->firstOrFail();
        $registrations->cancel($registration, 'Dismissed from the center list', Actor::platform($user));
    }

    public function render(RegisteredCenterAddress $addresses, PlatformCurrencies $currencies): mixed
    {
        $user = $this->requirePlatformPermission('platform.center.view');

        $centers = $this->filtered()
            ->with('domains')
            ->select(['id', 'name', 'slug', 'currency', 'contact_name', 'status', 'provisioning_status', 'migration_status', 'created_at'])
            ->orderBy($this->sortBy, $this->sortDirection === 'asc' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate($this->perPage);

        $subscriptions = Subscription::query()
            ->with('plan')
            ->whereIn('tenant_id', $centers->getCollection()->pluck('id'))
            ->get()
            ->keyBy('tenant_id');

        $locale = app()->getLocale();
        $now = Carbon::now();

        return view('livewire.sadmin.centers.index', [
            'centers' => $centers,
            'canManage' => $user->hasPermission('platform.center.manage'),
            'defaultCurrency' => Currency::default()->value,
            'currencies' => $currencies->all()->pluck('code')->all(),
            'creating' => $user->hasPermission('platform.center.manage') ? Registration::query()
                ->whereIn('status', [RegistrationStatus::Preparing->value, RegistrationStatus::Failed->value])
                ->where('created_at', '>=', now()->subDays(14))
                ->latest()->limit(10)->get() : collect(),
            'rows' => $centers->getCollection()->mapWithKeys(function (TenantModel $tenant) use ($addresses, $subscriptions, $locale, $now, $currencies): array {
                /** @var Subscription|null $subscription */
                $subscription = $subscriptions->get($tenant->id);

                return [$tenant->id => [
                    'address' => $addresses->resolve($tenant),
                    'subscription' => $subscription === null ? null : [
                        'plan' => $this->planName($subscription, $locale),
                        'status' => $subscription->effectiveStatus($now)->value,
                        'price' => $subscription->price_minor_snapshot !== null && $subscription->currency_snapshot !== null ? $currencies->format((int) $subscription->price_minor_snapshot, $subscription->currency_snapshot, $locale) : null,
                        'cycle' => $subscription->billing_period_snapshot,
                        'trial_days' => $subscription->effectiveStatus($now)->isTrial() ? $subscription->trialDaysLeft($now) : null,
                        'renews' => $subscription->current_period_end,
                    ],
                ]];
            }),
            'counts' => $this->statusCounts(),
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            'hasFilters' => $this->search !== '' || $this->status !== '' || $this->provisioningStatus !== ''
                || $this->plan !== '' || $this->subscription !== '' || $this->trial !== '' || $this->cycle !== '' || $this->currency !== '',
        ]);
    }

    /**
     * Every filter except the lifecycle status, which the tabs count.
     *
     * @return Builder<TenantModel>
     */
    private function filtered(bool $withStatus = true): Builder
    {
        $subscriptions = fn () => Subscription::query()->select('tenant_id');

        return TenantModel::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('slug', 'like', '%'.$this->search.'%')))
            ->when($withStatus && $this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($withStatus && $this->status === '', fn (Builder $q) => $q->where('status', '!=', 'archived'))
            ->when($this->cycle !== '', fn (Builder $q) => $q->whereIn('id', $subscriptions()->where('billing_period_snapshot', $this->cycle)))
            ->when($this->currency !== '', fn (Builder $q) => $this->currency === Currency::default()->value
                ? $q->where(fn (Builder $inner) => $inner->where('currency', $this->currency)->orWhereNull('currency'))
                : $q->where('currency', $this->currency))
            ->when($this->provisioningStatus !== '', fn (Builder $q) => $q->where('provisioning_status', $this->provisioningStatus))
            ->when($this->plan !== '' && ctype_digit($this->plan), fn (Builder $q) => $q->whereIn('id', $subscriptions()->where('plan_id', (int) $this->plan)))
            ->when($this->subscription === 'none', fn (Builder $q) => $q->whereNotIn('id', $subscriptions()))
            ->when($this->subscription !== '' && $this->subscription !== 'none', fn (Builder $q) => $q->whereIn('id', $subscriptions()->where('status', $this->subscription)))
            ->when($this->trial === 'ending', fn (Builder $q) => $q->whereIn('id', $subscriptions()
                ->where('status', 'trialing')
                ->whereBetween('trial_ends_at', [Carbon::now(), Carbon::now()->addDays(self::TRIAL_WARNING_DAYS)])))
            ->when($this->trial === 'expired', fn (Builder $q) => $q->whereIn('id', $subscriptions()
                ->where('status', 'trialing')
                ->where('trial_ends_at', '<', Carbon::now())));
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = $this->filtered(withStatus: false)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return ['' => array_sum($counts) - ($counts['archived'] ?? 0)] + $counts;
    }

    private function planName(Subscription $subscription, string $locale): string
    {
        $name = $subscription->plan?->name?->get($locale);

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $snapshot = $subscription->plan_name_snapshot;

        if (is_array($snapshot)) {
            $text = $snapshot[$locale] ?? $snapshot['en'] ?? reset($snapshot);

            return is_string($text) && $text !== '' ? $text : '—';
        }

        return '—';
    }
}
