<div class="dash manager-dash">
    <x-ui.page-header :title="__('manager_dashboard.title')" :subtitle="$tenant->name">
        @if($plan)
            <x-slot:meta>
                @if($plan['href'])
                    <a class="dash-plan" href="{{ $plan['href'] }}" wire:navigate aria-label="{{ __('manager_dashboard.plan.view') }}: {{ $plan['name'] }}, {{ $plan['label'] }}">
                        @include('livewire.center.dashboard.plan-chip')
                    </a>
                @else
                    <span class="dash-plan">@include('livewire.center.dashboard.plan-chip')</span>
                @endif
            </x-slot:meta>
        @endif
        <x-slot:actions>
            @if($actions['reports'])
                <x-ui.button variant="ghost" icon="reports" :href="$actions['reports']" wire:navigate>{{ __('manager_dashboard.actions.reports') }}</x-ui.button>
            @endif
            @if($actions['pos'])
                <x-ui.button variant="secondary" icon="pos" :href="$actions['pos']" wire:navigate>{{ __('manager_dashboard.actions.pos') }}</x-ui.button>
            @endif
            @if($actions['booking'])
                <x-ui.button icon="plus" :href="$actions['booking']" wire:navigate>{{ __('manager_dashboard.actions.new_booking') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($failed)
        <x-ui.notice tone="danger" :message="__('manager_dashboard.error')" />
    @endif

    <div class="dash-controls">
        <x-ui.date-range :range="$this->range" :current="$period" :open="$customOpen" />
        <div class="dash-controls__aside">
            @if($branchOptions !== [])
                <label class="dash-branch">
                    <x-ui.icon name="branches" size="16" />
                    <select wire:model.live="branch" aria-label="{{ __('manager_dashboard.branch.label') }}">
                        <option value="">{{ __('manager_dashboard.branch.all') }}</option>
                        @foreach($branchOptions as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <span class="dash-updated">{{ __('manager_dashboard.updated', ['time' => $updatedAt]) }}</span>
            <button type="button" class="icon-button icon-button--sm" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh" aria-label="{{ __('manager_dashboard.refresh') }}" title="{{ __('manager_dashboard.refresh') }}">
                <x-ui.icon name="refresh" size="16" />
            </button>
        </div>
    </div>

    <div class="dash" wire:loading.class="is-refreshing" wire:target="setRange,applyCustomRange,branch,refresh">
        @if($live !== [])
            <section class="dash-live" aria-labelledby="dash-live-title">
                <h2 class="dash-live__title" id="dash-live-title"><span class="dash-live__pulse" aria-hidden="true"></span>{{ __('manager_dashboard.live.label') }}</h2>
                <div class="dash-live__items">
                    @foreach($live as $stat)
                        <x-ui.stat :label="$stat['label']" :value="$stat['value']" :hint="$stat['hint']" :icon="$stat['icon']" :tone="$stat['tone']" :href="$stat['href']" />
                    @endforeach
                </div>
            </section>
        @endif

        @foreach($kpis as $group)
            <section class="dash-group" wire:key="dash-kpis-{{ $group['key'] }}" aria-labelledby="dash-kpis-{{ $group['key'] }}">
                <h2 class="dash-group__title" id="dash-kpis-{{ $group['key'] }}">{{ $group['label'] }}</h2>
                <div class="kpi-grid">
                    @foreach($group['items'] as $kpi)
                        @include('livewire.center.dashboard.kpi', ['kpi' => $kpi])
                    @endforeach
                </div>
            </section>
        @endforeach

        @include('livewire.center.dashboard.charts', ['rows' => $charts])

        @include('livewire.center.dashboard.now', ['now' => $now, 'hasNow' => $hasNow])

        @if($isEmpty && ! $failed)
            <x-ui.empty-state icon="dashboard" :title="__('manager_dashboard.empty.title')" :description="__('manager_dashboard.empty.description')" />
        @endif

        @if($locked !== [])
            <section class="dash-locked" aria-labelledby="dash-locked-title">
                <h2 id="dash-locked-title"><x-ui.icon name="lock" size="16" />{{ __('manager_dashboard.locked.title') }}</h2>
                <ul>
                    @foreach($locked as $feature)
                        <li>
                            <strong>{{ $feature['name'] }}</strong>
                            <span>{{ $feature['label'] }}</span>
                            @if($feature['href'])<a href="{{ $feature['href'] }}" wire:navigate>{{ __('manager_dashboard.locked.view_plans') }}</a>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
