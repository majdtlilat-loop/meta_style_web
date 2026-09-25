<div class="std-reports" x-data="standardReports" x-bind:class="{ 'is-switching': switching }">
    <x-ui.page-header :eyebrow="__('manager_reports.page.eyebrow')" :title="__('manager_reports.page.title')">
        <x-slot:actions>
            <a class="button button--secondary reports-pro-link" href="{{ $advancedUrl }}" wire:navigate>
                <x-ui.icon name="advanced-reports" />{{ __('manager_reports.page.advanced_link') }}@if($advancedLock)<span class="reports-pro-link__lock"><x-ui.icon name="lock" size="12" />{{ $advancedLock }}</span>@endif
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if($state === 'forbidden')
        <x-ui.empty-state icon="lock" :title="__('manager_reports.states.forbidden_title')" :description="__('manager_reports.states.forbidden_body')" />
    @else
        @if($tabs !== [])
            <nav class="std-tabs" aria-label="{{ __('manager_reports.page.catalog') }}">
                @foreach($tabs as $tab)
                    <a href="{{ $tab['href'] }}" wire:navigate x-on:click="open($event)" wire:key="std-tab-{{ $tab['code'] }}" @if($tab['active']) aria-current="page" @endif>
                        <x-ui.icon :name="$tab['icon']" size="16" /><span>{{ $tab['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        @if($state !== 'no_reports')
            @include('livewire.center.reports.standard.toolbar')
        @endif

        <div class="std-body" x-show="! switching" wire:loading.class="is-refreshing" wire:target="setRange,applyCustomRange,branch,source,status,employee,service,category,resetFilters,clearFilters,refresh">
            @if($state === 'no_reports')
                <x-ui.empty-state icon="reports" :title="__('manager_reports.states.no_reports_title')" />
            @elseif($state === 'unknown')
                <x-ui.empty-state icon="alert-circle" :title="__('manager_reports.states.unknown_title')">
                    @if($fallback)<a class="button button--secondary" href="{{ $fallback['href'] }}" wire:navigate>{{ $fallback['label'] }}</a>@endif
                </x-ui.empty-state>
            @elseif($state === 'report_forbidden')
                <x-ui.empty-state icon="lock" :title="__('manager_reports.states.report_forbidden_title')">
                    @if($fallback)<a class="button button--secondary" href="{{ $fallback['href'] }}" wire:navigate>{{ $fallback['label'] }}</a>@endif
                </x-ui.empty-state>
            @elseif($error)
                <x-ui.notice tone="danger" :message="$error" />
            @elseif($result)
                @include('livewire.center.reports.standard.report')
            @endif
        </div>

        @include('livewire.center.reports.standard.skeleton')
    @endif
</div>
