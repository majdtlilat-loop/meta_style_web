<div class="adv-page">
    <x-ui.page-header :title="__('manager_advanced.page.title')" class="adv-header">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="reports" :href="$standardUrl" wire:navigate>{{ __('manager_advanced.page.standard_link') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($state === 'forbidden')
        <x-ui.empty-state icon="lock" :title="__('manager_advanced.states.forbidden_title')" :description="__('manager_advanced.states.forbidden_body')" />
    @elseif($state === 'locked')
        <div class="adv-lock"><x-manager.feature-locked :offer="$offer" /></div>
    @else
        <section class="adv-toolbar" aria-label="{{ __('manager_advanced.toolbar.label') }}" x-data="{ filters: false }">
            <div class="adv-toolbar__row">
                <details class="dropdown adv-period" data-popover wire:key="adv-period-{{ $period->preset }}-{{ $periodLabel }}-{{ $customOpen ? 'custom' : 'preset' }}">
                    <summary class="button button--secondary adv-period__trigger" aria-haspopup="true">
                        <x-ui.icon name="calendar" size="16" />
                        <span class="adv-period__text">
                            <strong>{{ __('manager_advanced.range.'.$period->preset) }}</strong>
                            <small dir="auto">{{ $periodLabel }}</small>
                        </span>
                        <x-ui.icon name="chevron-down" size="14" />
                    </summary>
                    <div class="dropdown__panel dropdown__panel--start adv-period__panel" role="group" aria-label="{{ __('manager_advanced.toolbar.period') }}">
                        @foreach($presets as $preset)
                            <button type="button" class="menu-item" wire:click="setRange('{{ $preset }}')" aria-pressed="{{ $period->preset === $preset ? 'true' : 'false' }}">
                                <span>{{ __('manager_advanced.range.'.$preset) }}</span>
                                @if($period->preset === $preset)<x-ui.icon name="check" size="16" />@endif
                            </button>
                        @endforeach
                    </div>
                </details>

                {{-- On a phone the comparison and branch fold behind one toggle. --}}
                <button type="button" class="button button--secondary button--sm adv-toolbar__toggle" x-on:click="filters = ! filters" x-bind:aria-expanded="filters.toString()" aria-expanded="false" aria-controls="adv-filters">
                    <x-ui.icon name="filter" size="16" />{{ __('manager_advanced.toolbar.filters') }}@if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
                </button>

                <div id="adv-filters" class="adv-filters" x-bind:class="{ 'is-open': filters }">
                    <div class="segmented adv-compare" role="group" aria-label="{{ __('manager_advanced.toolbar.compare') }}">
                        @foreach(['previous', 'last_year'] as $mode)
                            <button type="button" wire:click="setComparison('{{ $mode }}')" aria-pressed="{{ $period->comparison === $mode ? 'true' : 'false' }}">{{ __('manager_advanced.compare_mode.'.$mode) }}</button>
                        @endforeach
                    </div>

                    @if($branchOptions !== [])
                        <label class="adv-branch">
                            <x-ui.icon name="branches" size="16" />
                            <select wire:model.live="branch" aria-label="{{ __('manager_advanced.toolbar.branch') }}">
                                <option value="">{{ __('manager_advanced.toolbar.all_branches') }}</option>
                                @foreach($branchOptions as $option)
                                    <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>

                <div class="adv-toolbar__actions">
                    @if($filtered)
                        <button type="button" class="button button--ghost button--sm" wire:click="resetFilters"><x-ui.icon name="reset" size="16" />{{ __('manager_advanced.toolbar.reset') }}</button>
                    @endif
                    <button type="button" class="icon-button" wire:click="refreshData" wire:loading.attr="disabled" wire:target="refreshData" aria-label="{{ __('manager_advanced.toolbar.refresh') }}" title="{{ __('manager_advanced.toolbar.refresh') }}"><x-ui.icon name="refresh" size="16" /></button>
                    @if($exports !== [])
                        <details class="dropdown adv-export" data-popover>
                            <summary class="button button--secondary button--sm"><x-ui.icon name="download" size="16" />{{ __('manager_advanced.toolbar.export') }}</summary>
                            <div class="dropdown__panel adv-export__panel">
                                <p class="menu-label">{{ __('manager_advanced.toolbar.export_csv') }}</p>
                                @foreach($exports as $export)
                                    <a class="menu-item" href="{{ $export['href'] }}" download><x-ui.icon name="file-text" size="16" />{{ $export['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    <button type="button" class="button button--secondary button--sm" onclick="window.print()"><x-ui.icon name="print" size="16" />{{ __('manager_advanced.toolbar.print') }}</button>
                </div>
            </div>

            <p class="adv-toolbar__summary">
                <span>{{ $periodLabel }}</span>
                <span class="adv-toolbar__versus"><x-ui.icon name="history" size="14" />{{ __('manager_advanced.toolbar.versus', ['period' => $comparisonLabel]) }}</span>
                @if($branchName)<span class="adv-toolbar__branch"><x-ui.icon name="branches" size="14" />{{ $branchName }}</span>@endif
                <span class="spinner" wire:loading wire:target="setRange,applyCustomRange,setComparison,branch,resetFilters,refreshData" aria-hidden="true"></span>
            </p>

            @if($customOpen)
                <form class="adv-custom" wire:submit="applyCustomRange" novalidate>
                    <x-ui.field :label="__('ui.fields.from')" for="adv-range-from" name="customFrom">
                        <input id="adv-range-from" type="date" wire:model="customFrom" max="{{ $today }}" @error('customFrom') aria-invalid="true" @enderror>
                    </x-ui.field>
                    <x-ui.field :label="__('ui.fields.to')" for="adv-range-to" name="customTo">
                        <input id="adv-range-to" type="date" wire:model="customTo" max="{{ $today }}" @error('customTo') aria-invalid="true" @enderror>
                    </x-ui.field>
                    <div class="adv-custom__actions">
                        <x-ui.button variant="ghost" wire:click="cancelCustomRange">{{ __('ui.actions.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="applyCustomRange">{{ __('ui.actions.apply') }}</x-ui.button>
                    </div>
                </form>
            @endif
        </section>

        <nav class="tabs adv-views" aria-label="{{ __('manager_advanced.views.label') }}">
            @foreach(['insights' => 'sparkles', 'compare' => 'layers', 'reports' => 'file-text'] as $key => $icon)
                <button type="button" wire:click="setView('{{ $key }}')" @if($view === $key) aria-current="page" @endif><x-ui.icon :name="$icon" size="16" />{{ __('manager_advanced.views.'.$key) }}</button>
            @endforeach
        </nav>

        <div class="adv-body" wire:loading.class="is-refreshing" wire:target="setRange,applyCustomRange,setComparison,branch,resetFilters,refreshData,setView">
            @if($view === 'insights')
                <livewire:center.advanced-reports.workspace :range="$scope['range']" :from="$scope['from']" :to="$scope['to']" :comparison="$scope['comparison']" :branch="$scope['branch']" :version="$scope['version']" wire:key="adv-workspace" />
                @if($hasReports)
                    <livewire:center.advanced-reports.ai-insights report="" :range="$scope['range']" :from="$scope['from']" :to="$scope['to']" :comparison="$scope['comparison']" :branch="$scope['branch']" wire:key="adv-ai-insights" />
                @endif
            @elseif($view === 'compare')
                <livewire:center.advanced-reports.compare :range="$scope['range']" :from="$scope['from']" :to="$scope['to']" :comparison="$scope['comparison']" :branch="$scope['branch']" :version="$scope['version']" wire:key="adv-compare" />
            @elseif(! $hasReports)
                <x-ui.empty-state icon="advanced-reports" :title="__('manager_advanced.states.no_reports_title')" />
            @else
                <nav class="adv-report-tabs" aria-label="{{ __('manager_advanced.views.reports') }}">
                    @foreach($reportTabs as $tab)
                        <a href="{{ $tab['href'] }}" wire:navigate @if($tab['code'] === $reportCode) aria-current="page" @endif>{{ __('manager_advanced.reports.'.$tab['code']) }}</a>
                    @endforeach
                </nav>
                <livewire:center.advanced-reports.library :report="$reportCode" :range="$scope['range']" :from="$scope['from']" :to="$scope['to']" :comparison="$scope['comparison']" :branch="$scope['branch']" :version="$scope['version']" wire:key="adv-library-{{ $reportCode }}" />
                <livewire:center.advanced-reports.ai-insights :report="$reportCode" :range="$scope['range']" :from="$scope['from']" :to="$scope['to']" :comparison="$scope['comparison']" :branch="$scope['branch']" wire:key="adv-ai-{{ $reportCode }}" />
            @endif
        </div>
    @endif
</div>
