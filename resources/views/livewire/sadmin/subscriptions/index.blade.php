@php
    use App\View\Label;

    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : null;
    $statuses = \App\Livewire\Sadmin\Subscriptions\Index::STATUSES;
    $activeFilters = collect([$plan, $due, $cycle])->filter(fn ($value) => $value !== '')->count();
    $kind = $panel !== null ? explode(':', $panel, 2)[0] : null;
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_subscriptions.title')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('sadmin_subscriptions.results', $subscriptions->total(), ['count' => number_format($subscriptions->total())]) }}</span>
        </x-slot:meta>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('sadmin_subscriptions.filters.status') }}">
        <button type="button" wire:click="setStatus('')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}">{{ __('sadmin_subscriptions.filters.all') }}<span class="segmented__count">{{ number_format($counts[''] ?? 0) }}</span></button>
        @foreach($statuses as $value)
            <button type="button" wire:click="setStatus('{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">{{ Label::for('subscription_status', $value) }}<span class="segmented__count">{{ number_format($counts[$value] ?? 0) }}</span></button>
        @endforeach
    </div>

    <form class="filter-bar" role="search" aria-label="{{ __('sadmin_subscriptions.filters.label') }}" x-on:submit.prevent data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="subscription-search">{{ __('sadmin_subscriptions.filters.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="subscription-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('sadmin_subscriptions.filters.search_placeholder') }}" autocomplete="off">
            </div>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="subscription-plan">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
            @if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
        </button>
        <div class="field">
            <label for="subscription-plan">{{ __('sadmin_subscriptions.filters.plan') }}</label>
            <select id="subscription-plan" wire:model.live="plan">
                <option value="">{{ __('sadmin_subscriptions.filters.all_plans') }}</option>
                @foreach($plans as $option)
                    <option value="{{ $option->id }}">{{ $planNames[$option->id] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="subscription-cycle">{{ __('sadmin_subscriptions.filters.cycle') }}</label>
            <select id="subscription-cycle" wire:model.live="cycle">
                <option value="">{{ __('sadmin_subscriptions.filters.all_cycles') }}</option>
                @foreach(['monthly', 'yearly'] as $value)<option value="{{ $value }}">{{ Label::for('billing_period', $value) }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="subscription-due">{{ __('sadmin_subscriptions.filters.due') }}</label>
            <select id="subscription-due" wire:model.live="due">
                <option value="">{{ __('sadmin_subscriptions.filters.due_any') }}</option>
                <option value="trial">{{ __('sadmin_subscriptions.filters.due_trial') }}</option>
                <option value="renewal">{{ __('sadmin_subscriptions.filters.due_renewal') }}</option>
            </select>
        </div>
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('sadmin_subscriptions.filters.clear') }}</button>
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,status,setStatus,plan,cycle,due,clearFilters,gotoPage,nextPage,previousPage">
        @if($subscriptions->isEmpty())
            <x-ui.empty-state :icon="$hasFilters ? 'filter' : 'subscriptions'" :title="$hasFilters ? __('sadmin_subscriptions.empty.title') : __('sadmin_subscriptions.empty.none_title')" :description="$hasFilters ? __('sadmin_subscriptions.empty.description') : null">
                @if($hasFilters)<button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('sadmin_subscriptions.filters.clear') }}</button>@endif
            </x-ui.empty-state>
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_subscriptions.title') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('sadmin_subscriptions.table.center') }}</th>
                        <th scope="col">{{ __('sadmin_subscriptions.table.plan') }}</th>
                        <th scope="col">{{ __('sadmin_subscriptions.table.status') }}</th>
                        <th scope="col">{{ __('sadmin_subscriptions.table.next') }}</th>
                        <th scope="col">{{ __('sadmin_subscriptions.table.change') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($subscriptions as $subscription)
                        @php
                            $effective = $subscription->effectiveStatus()->value;
                            $next = match (true) {
                                $effective === 'trialing' && $subscription->trial_ends_at !== null => __('sadmin_subscriptions.next.trial', ['date' => $date($subscription->trial_ends_at)]),
                                $effective === 'expired' && $subscription->trial_ends_at !== null => __('sadmin_subscriptions.next.expired', ['date' => $date($subscription->trial_ends_at)]),
                                $effective === 'cancelled' && $subscription->cancelled_at !== null => __('sadmin_subscriptions.next.cancelled', ['date' => $date($subscription->cancelled_at)]),
                                $effective === 'past_due' && $subscription->grace_ends_at !== null => __('sadmin_subscriptions.next.grace', ['date' => $date($subscription->grace_ends_at)]),
                                $subscription->current_period_end !== null && in_array($effective, ['active', 'past_due'], true) => __('sadmin_subscriptions.next.renews', ['date' => $date($subscription->current_period_end)]),
                                default => null,
                            };
                            $change = $pending->get($subscription->id);
                        @endphp
                        <tr wire:key="subscription-{{ $subscription->id }}">
                            <td data-label="{{ __('sadmin_subscriptions.table.center') }}" data-primary>
                                <a class="cell-title" href="{{ route('superadmin.centers.show', ['tenant' => $subscription->tenant_id, 'tab' => 'subscription']) }}" wire:navigate>{{ $subscription->tenant?->name ?? '—' }}</a>
                                @if($subscription->tenant?->slug)<span class="cell-sub" dir="ltr">{{ $subscription->tenant->slug }}</span>@endif
                            </td>
                            <td data-label="{{ __('sadmin_subscriptions.table.plan') }}">
                                <span class="cell-title">{{ $planNames[$subscription->plan_id] ?? '—' }}</span>
                                @if($prices[$subscription->id])<span class="cell-sub"><span dir="ltr" class="tabular">{{ $prices[$subscription->id] }}</span> · {{ Label::for('billing_period', $subscription->billing_period_snapshot) }}</span>@endif
                            </td>
                            <td data-label="{{ __('sadmin_subscriptions.table.status') }}">
                                <x-ui.status :value="$effective" :label="Label::for('subscription_status', $effective)" />
                            </td>
                            <td data-label="{{ __('sadmin_subscriptions.table.next') }}">{{ $next ?? __('sadmin_subscriptions.none') }}</td>
                            <td data-label="{{ __('sadmin_subscriptions.table.change') }}">
                                @if($change)
                                    <x-ui.status value="scheduled" :label="__('sadmin_subscriptions.pending', ['plan' => $planNames[$change->target_plan_id] ?? '—', 'date' => $date($change->effective_at)])" :dot="false" />
                                @else
                                    <span class="muted">{{ __('sadmin_subscriptions.none') }}</span>
                                @endif
                            </td>
                            <td class="actions">
                                <button class="button button--secondary button--sm" type="button" wire:click="openPanel('plan:{{ $subscription->id }}')"><x-ui.icon name="plans" size="16" />{{ __('sadmin_subscriptions.actions.change') }}</button>
                                <details class="dropdown" data-popover>
                                    <summary class="icon-button icon-button--sm" aria-label="{{ __('sadmin_subscriptions.actions.more', ['name' => $subscription->tenant?->name]) }}"><x-ui.icon name="more" /></summary>
                                    <div class="dropdown__panel" role="menu">
                                        @if(in_array($effective, ['trialing', 'expired', 'past_due'], true))
                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('activate:{{ $subscription->id }}')"><x-ui.icon name="check-circle" />{{ __('sadmin_subscriptions.actions.activate') }}</button>
                                        @endif
                                        @if(in_array($effective, ['trialing', 'expired'], true))
                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('trial:{{ $subscription->id }}')"><x-ui.icon name="clock" />{{ __('sadmin_subscriptions.actions.trial') }}</button>
                                        @elseif(! in_array($effective, ['cancelled', 'expired'], true))
                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('renewal:{{ $subscription->id }}')"><x-ui.icon name="calendar" />{{ __('sadmin_subscriptions.actions.renewal') }}</button>
                                        @endif
                                        <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('schedule:{{ $subscription->id }}')"><x-ui.icon name="clock" />{{ __('sadmin_subscriptions.actions.schedule') }}</button>
                                        <div class="menu-separator" role="separator"></div>
                                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $subscription->tenant_id, 'tab' => 'subscription']) }}" wire:navigate><x-ui.icon name="history" />{{ __('sadmin_subscriptions.actions.history') }}</a>
                                        @if(in_array($subscription->tenant?->status, ['active', 'suspended'], true))
                                            <a class="menu-item {{ $subscription->tenant?->status === 'active' ? 'menu-item--danger' : '' }}" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $subscription->tenant_id, 'tab' => 'lifecycle', 'panel' => 'lifecycle:'.($subscription->tenant?->status === 'active' ? 'suspended' : 'active')]) }}" wire:navigate><x-ui.icon :name="$subscription->tenant?->status === 'active' ? 'pause' : 'play'" />{{ __('sadmin_centers.lifecycle.confirm.'.($subscription->tenant?->status === 'active' ? 'suspended' : 'active')) }}</a>
                                            <a class="menu-item menu-item--danger" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $subscription->tenant_id, 'tab' => 'lifecycle', 'panel' => 'lifecycle:cancelled']) }}" wire:navigate><x-ui.icon name="x-circle" />{{ __('sadmin_subscriptions.actions.cancel') }}</a>
                                        @endif
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $subscriptions->links() }}

    @if($selected && $kind)
        @php
            $chosen = $activePlans->firstWhere('id', (int) $targetPlanId);
            $icons = ['plan' => 'plans', 'schedule' => 'clock', 'activate' => 'check-circle', 'trial' => 'clock', 'renewal' => 'calendar'];
        @endphp
        <x-ui.modal :title="__('sadmin_subscriptions.forms.'.$kind.'.title')" :description="$selected->tenant?->name" :icon="$icons[$kind] ?? 'info'" submit="apply">
            <dl class="summary-list">
                <div><dt>{{ __('sadmin_subscriptions.form.current') }}</dt><dd>{{ $planNames[$selected->plan_id] ?? '—' }} · {{ Label::for('billing_period', $selected->billing_period_snapshot) }}</dd></div>
            </dl>
            @if(in_array($kind, ['plan', 'schedule'], true))
                <x-ui.field :label="__('sadmin_subscriptions.form.target_plan')" for="sub-plan" name="targetPlanId" required>
                    <select id="sub-plan" wire:model.live="targetPlanId" required>
                        <option value="">{{ __('sadmin_subscriptions.form.choose_plan') }}</option>
                        @foreach($activePlans as $option)
                            <option value="{{ $option->id }}">{{ $planNames[$option->id] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif
            @if(in_array($kind, ['plan', 'activate'], true))
                @php $cyclePlan = $kind === 'plan' ? $chosen : $selected->plan; @endphp
                <fieldset class="field">
                    <legend>{{ __('sadmin_subscriptions.fields.cycle') }}</legend>
                    <div class="segmented" role="radiogroup">
                        @foreach(['monthly', 'yearly'] as $option)
                            @php $optionPrice = $cyclePlan?->priceFor($option); @endphp
                            <label @class(['is-active' => $targetCycle === $option, 'is-disabled' => $cyclePlan && $optionPrice === null])>
                                <input class="sr-only" type="radio" name="sub-cycle" value="{{ $option }}" wire:model.live="targetCycle" @disabled($cyclePlan && $optionPrice === null)>
                                {{ Label::for('billing_period', $option) }}@if($optionPrice !== null) · <span dir="ltr">{{ $money->format($optionPrice, $cyclePlan->currency, app()->getLocale()) }}</span>@endif
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif
            @if($kind === 'schedule')
                <x-ui.field :label="__('sadmin_subscriptions.form.effective_at')" for="sub-effective" name="effectiveAt" required>
                    <input id="sub-effective" type="datetime-local" wire:model="effectiveAt" min="{{ now()->format('Y-m-d\TH:i') }}" required>
                </x-ui.field>
            @elseif(in_array($kind, ['activate', 'trial', 'renewal'], true))
                <x-ui.field :label="__('sadmin_subscriptions.forms.'.$kind.'.date')" for="sub-date" name="date" required>
                    <input id="sub-date" type="date" wire:model="date" @if($kind !== 'activate') min="{{ now()->addDay()->format('Y-m-d') }}" @endif required>
                </x-ui.field>
            @endif
            <x-ui.field :label="__('sadmin_subscriptions.form.reason')" for="sub-reason" name="reason" required>
                <textarea id="sub-reason" rows="2" wire:model="reason" required></textarea>
            </x-ui.field>
            <p class="field-help">{{ __('sadmin_subscriptions.forms.'.$kind.'.note') }}</p>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="apply">{{ __('sadmin_subscriptions.forms.'.$kind.'.submit') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
