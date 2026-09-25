@php
    use App\View\Label;

    $lifecycleTabs = ['' => __('sadmin_centers.filters.all'), 'active' => null, 'suspended' => null, 'cancelled' => null, 'provisioning' => null, 'failed' => null, 'archived' => null];
    $sortable = [
        'name' => __('sadmin_centers.table.center'),
        'status' => __('sadmin_centers.table.lifecycle'),
        'provisioning_status' => __('sadmin_centers.table.provisioning'),
        'created_at' => __('sadmin_centers.table.created'),
    ];
    $sortHeader = function (string $column) use ($sortBy, $sortDirection, $sortable): string {
        $active = $sortBy === $column;
        $aria = $active ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none';
        $icon = $active ? ($sortDirection === 'asc' ? 'arrow-up' : 'arrow-down') : 'chevrons-up-down';

        return '<th scope="col" aria-sort="'.$aria.'"><button class="table-sort" type="button" wire:click="sort(\''.$column.'\')" aria-label="'.e(__('sadmin_centers.sort.by', ['column' => $sortable[$column]])).'">'
            .e($sortable[$column]).view('components.ui.icon', ['name' => $icon, 'size' => 14])->render().'</button></th>';
    };
    $activeFilters = collect([$plan, $subscription, $trial, $provisioningStatus, $cycle, $currency])->filter(fn ($value) => $value !== '')->count();
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_centers.title')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('sadmin_centers.results', $centers->total(), ['count' => number_format($centers->total())]) }}</span>
        </x-slot:meta>
        @if($canManage)
            <x-slot:actions>
                <a class="button" href="{{ route('superadmin.centers.create') }}" wire:navigate><x-ui.icon name="plus" size="16" />{{ __('sadmin_centers.actions.add') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    @if($creating->isNotEmpty())
        <section class="card card--flush" aria-labelledby="centers-creating" wire:poll.10s>
            <header class="card__header"><h2 id="centers-creating">{{ __('sadmin_centers.creating.title') }}</h2></header>
            <ul class="row-list">
                @foreach($creating as $registration)
                    <li class="row-list__item" wire:key="creating-{{ $registration->uuid }}">
                        <div class="row-list__body">
                            <span class="cell-title">{{ $registration->center_name }}</span>
                            <span class="cell-sub"><span dir="ltr">{{ $registration->requested_slug }}</span> · {{ $registration->source === 'platform' ? __('sadmin_centers.creating.by', ['name' => $registration->created_by_label]) : __('sadmin_centers.creating.self') }} · {{ $registration->created_at?->diffForHumans() }}</span>
                        </div>
                        <div class="cluster cluster--tight">
                            @if($registration->status->value === 'failed')
                                <x-ui.status value="failed" :label="__('sadmin_centers.creating.failed')" />
                                <button class="button button--secondary button--sm" type="button" wire:click="retryCreation('{{ $registration->uuid }}')"><x-ui.icon name="refresh" size="16" />{{ __('sadmin_centers.creating.retry') }}</button>
                                <button class="button button--ghost button--sm" type="button" wire:click="dismissCreation('{{ $registration->uuid }}')" wire:confirm="{{ __('sadmin_centers.creating.dismiss_confirm') }}" data-confirm-title="{{ __('sadmin_centers.creating.dismiss') }}" data-confirm-tone="danger">{{ __('sadmin_centers.creating.dismiss') }}</button>
                            @else
                                <x-ui.status value="running" tone="info" :label="__('sadmin_centers.creating.running')" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('sadmin_centers.filters.lifecycle') }}">
        @foreach($lifecycleTabs as $value => $label)
            <button type="button" wire:click="setStatus('{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                {{ $label ?? Label::for('tenant_status', $value) }}
                <span class="segmented__count">{{ number_format($counts[$value] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <form class="filter-bar" role="search" aria-label="{{ __('sadmin_centers.filters.label') }}" x-on:submit.prevent data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="center-search">{{ __('sadmin_centers.search.label') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="center-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('sadmin_centers.search.placeholder') }}" autocomplete="off">
            </div>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="center-plan">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
            @if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
        </button>
        <div class="field">
            <label for="center-plan">{{ __('sadmin_centers.filters.plan') }}</label>
            <select id="center-plan" wire:model.live="plan">
                <option value="">{{ __('sadmin_centers.filters.all_plans') }}</option>
                @foreach($plans as $option)
                    <option value="{{ $option->id }}">{{ $option->name?->get() }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="center-subscription">{{ __('sadmin_centers.filters.subscription') }}</label>
            <select id="center-subscription" wire:model.live="subscription">
                <option value="">{{ __('sadmin_centers.filters.all_subscriptions') }}</option>
                @foreach(['trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired'] as $value)
                    <option value="{{ $value }}">{{ Label::for('subscription_status', $value) }}</option>
                @endforeach
                <option value="none">{{ __('sadmin_centers.filters.no_subscription') }}</option>
            </select>
        </div>
        <div class="field">
            <label for="center-cycle">{{ __('sadmin_centers.filters.cycle') }}</label>
            <select id="center-cycle" wire:model.live="cycle">
                <option value="">{{ __('sadmin_centers.filters.all_cycles') }}</option>
                @foreach(['monthly', 'yearly'] as $value)
                    <option value="{{ $value }}">{{ Label::for('billing_period', $value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="center-currency">{{ __('sadmin_centers.filters.currency') }}</label>
            <select id="center-currency" wire:model.live="currency">
                <option value="">{{ __('sadmin_centers.filters.all_currencies') }}</option>
                @foreach($currencies as $code)
                    <option value="{{ $code }}">{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="center-trial">{{ __('sadmin_centers.filters.trial') }}</label>
            <select id="center-trial" wire:model.live="trial">
                <option value="">{{ __('sadmin_centers.filters.all_trials') }}</option>
                <option value="ending">{{ __('sadmin_centers.filters.trial_ending') }}</option>
                <option value="expired">{{ __('sadmin_centers.filters.trial_expired') }}</option>
            </select>
        </div>
        <div class="field">
            <label for="center-provisioning">{{ __('sadmin_centers.filters.provisioning') }}</label>
            <select id="center-provisioning" wire:model.live="provisioningStatus">
                <option value="">{{ __('sadmin_centers.filters.all_provisioning') }}</option>
                @foreach(['pending', 'running', 'completed', 'failed'] as $value)
                    <option value="{{ $value }}">{{ Label::for('provisioning_status', $value) }}</option>
                @endforeach
            </select>
        </div>
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters">
                    <x-ui.icon name="close" size="16" />{{ __('sadmin_centers.filters.clear') }}
                </button>
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,status,setStatus,plan,subscription,trial,cycle,currency,provisioningStatus,sort,perPage,clearFilters,gotoPage,nextPage,previousPage">
        @if($centers->isEmpty())
            @if($hasFilters)
                <x-ui.empty-state icon="filter" :title="__('sadmin_centers.empty.title')" :description="__('sadmin_centers.empty.description')">
                    <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('sadmin_centers.filters.clear') }}</button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="centers" :title="__('sadmin_centers.empty.none_title')">
                    @if($canManage)<a class="button button--sm" href="{{ route('superadmin.centers.create') }}" wire:navigate><x-ui.icon name="plus" size="16" />{{ __('sadmin_centers.actions.add') }}</a>@endif
                </x-ui.empty-state>
            @endif
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_centers.title') }}</caption>
                <thead>
                    <tr>
                        {!! $sortHeader('name') !!}
                        {!! $sortHeader('status') !!}
                        <th scope="col">{{ __('sadmin_centers.table.plan') }}</th>
                        {!! $sortHeader('provisioning_status') !!}
                        <th scope="col">{{ __('sadmin_centers.table.address') }}</th>
                        {!! $sortHeader('created_at') !!}
                        <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($centers as $center)
                        @php
                            $row = $rows[$center->id];
                            $address = $row['address'];
                            $commercial = $row['subscription'];
                        @endphp
                        <tr wire:key="center-{{ $center->id }}">
                            <td data-label="{{ __('sadmin_centers.table.center') }}" data-primary>
                                <a class="cell-title" href="{{ route('superadmin.centers.show', $center->id) }}" wire:navigate>{{ $center->name }}</a>
                                <span class="cell-sub">@if($center->slug)<span dir="ltr">{{ $center->slug }}</span> · @endif<span dir="ltr">{{ $center->currency ?: $defaultCurrency }}</span>@if($center->contact_name) · {{ $center->contact_name }}@endif</span>
                            </td>
                            <td data-label="{{ __('sadmin_centers.table.lifecycle') }}">
                                <x-ui.status :value="$center->status" :label="Label::for('tenant_status', $center->status)" />
                            </td>
                            <td data-label="{{ __('sadmin_centers.table.plan') }}">
                                @if($commercial)
                                    <span class="cell-title">{{ $commercial['plan'] }}</span>
                                    <span class="cell-sub cluster cluster--tight">
                                        <x-ui.status :value="$commercial['status']" :label="Label::for('subscription_status', $commercial['status'])" :dot="false" />
                                        @if($commercial['trial_days'] !== null)
                                            <span>{{ trans_choice('sadmin_centers.cell.trial_left', $commercial['trial_days'], ['count' => $commercial['trial_days']]) }}</span>
                                        @elseif($commercial['price'])
                                            <span dir="ltr" class="tabular">{{ $commercial['price'] }}</span>
                                        @endif
                                        @if($commercial['cycle'])<span>{{ Label::for('billing_period', $commercial['cycle']) }}</span>@endif
                                    </span>
                                @else
                                    <span class="muted">{{ __('sadmin_centers.cell.no_subscription') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('sadmin_centers.table.provisioning') }}">
                                <x-ui.status :value="$center->provisioning_status" :label="Label::for('provisioning_status', $center->provisioning_status)" />
                                @if($center->migration_status === 'failed')
                                    <span class="cell-sub text-danger">{{ __('sadmin_centers.cell.migration_failed') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('sadmin_centers.table.address') }}">
                                @if($address['available'])
                                    <a class="cell-link" href="{{ $address['urls']['public'] }}" target="_blank" rel="noopener" dir="ltr">{{ $address['host'] }}</a>
                                @else
                                    <span class="muted">{{ __('sadmin_centers.cell.no_address') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('sadmin_centers.table.created') }}" class="nowrap">
                                <time datetime="{{ $center->created_at?->toIso8601String() }}">{{ $center->created_at?->translatedFormat('j M Y') }}</time>
                            </td>
                            <td class="actions">
                                <a class="button button--secondary button--sm" href="{{ route('superadmin.centers.show', $center->id) }}" wire:navigate>{{ __('sadmin_centers.actions.view') }}</a>
                                <details class="dropdown" data-popover>
                                    <summary class="icon-button icon-button--sm" aria-label="{{ __('sadmin_centers.actions.more', ['name' => $center->name]) }}" title="{{ __('sadmin_centers.actions.more', ['name' => $center->name]) }}">
                                        <x-ui.icon name="more" />
                                    </summary>
                                    <div class="dropdown__panel" role="menu">
                                        @if($canManage)
                                            <a class="menu-item" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $center->id, 'panel' => 'edit']) }}" wire:navigate><x-ui.icon name="edit" />{{ __('sadmin_centers.actions.edit') }}</a>
                                        @endif
                                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $center->id, 'tab' => 'subscription']) }}" wire:navigate><x-ui.icon name="subscriptions" />{{ __('sadmin_centers.actions.manage_subscription') }}</a>
                                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $center->id, 'tab' => 'subscription', 'panel' => 'plan']) }}" wire:navigate><x-ui.icon name="plans" />{{ __('sadmin_centers.actions.change_plan') }}</a>
                                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $center->id, 'tab' => 'entitlements']) }}" wire:navigate><x-ui.icon name="entitlements" />{{ __('sadmin_centers.actions.entitlements') }}</a>
                                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $center->id, 'tab' => 'billing']) }}" wire:navigate><x-ui.icon name="billing" />{{ __('sadmin_centers.actions.billing') }}</a>
                                        @if($canManage && in_array($center->status, ['active', 'suspended'], true))
                                            <a class="menu-item {{ $center->status === 'active' ? 'menu-item--danger' : '' }}" role="menuitem" href="{{ route('superadmin.centers.show', ['tenant' => $center->id, 'tab' => 'lifecycle', 'panel' => 'lifecycle:'.($center->status === 'active' ? 'suspended' : 'active')]) }}" wire:navigate><x-ui.icon :name="$center->status === 'active' ? 'pause' : 'play'" />{{ __('sadmin_centers.lifecycle.confirm.'.($center->status === 'active' ? 'suspended' : 'active')) }}</a>
                                        @endif
                                        @if($address['available'])
                                            <div class="menu-separator" role="separator"></div>
                                            <a class="menu-item" role="menuitem" href="{{ $address['urls']['public'] }}" target="_blank" rel="noopener"><x-ui.icon name="external" />{{ __('sadmin_centers.actions.open_site') }}</a>
                                            <a class="menu-item" role="menuitem" href="{{ $address['urls']['login'] }}" target="_blank" rel="noopener"><x-ui.icon name="key" />{{ __('sadmin_centers.actions.open_login') }}</a>
                                            <button class="menu-item" role="menuitem" type="button" data-copy="{{ $address['urls']['public'] }}" data-copied="{{ __('sadmin_centers.actions.copied') }}"><x-ui.icon name="copy" />{{ __('sadmin_centers.actions.copy_site') }}</button>
                                            <button class="menu-item" role="menuitem" type="button" data-copy="{{ $address['urls']['login'] }}" data-copied="{{ __('sadmin_centers.actions.copied') }}"><x-ui.icon name="copy" />{{ __('sadmin_centers.actions.copy_login') }}</button>
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

    @if($centers->total() > 0)
        <div class="table-footer table-footer--bare">
            <div class="field field--inline">
                <label for="center-per-page">{{ __('sadmin_centers.filters.per_page') }}</label>
                <select id="center-per-page" wire:model.live="perPage">
                    @foreach([20, 50, 100] as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>
            {{ $centers->links() }}
        </div>
    @endif
</div>
