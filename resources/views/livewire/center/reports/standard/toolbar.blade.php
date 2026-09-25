{{-- One filter row above everything it scopes: the period and its comparison, then only the narrowing this report supports. --}}
<section class="std-toolbar" aria-label="{{ __('manager_reports.filters.label') }}" x-data="{ open: false }">
    <div class="std-toolbar__period">
        <div class="segmented segmented--scroll std-presets" role="group" aria-label="{{ __('manager_reports.std.period.label') }}">
            @foreach($toolbar['presets'] as $preset)
                <button type="button" wire:click="setRange('{{ $preset['key'] }}')" wire:key="std-preset-{{ $preset['key'] }}"
                        aria-pressed="{{ $preset['active'] ? 'true' : 'false' }}" @if($preset['key'] === 'custom') aria-expanded="{{ $customOpen ? 'true' : 'false' }}" @endif>
                    @if($preset['key'] === 'custom')<x-ui.icon name="calendar" size="14" />@endif{{ $preset['label'] }}
                </button>
            @endforeach
        </div>
        <p class="std-toolbar__summary">
            <strong>{{ $toolbar['current'] }}</strong>
            <span>{{ $toolbar['previous'] }}</span>
            <span class="spinner" wire:loading wire:target="setRange,applyCustomRange,branch,source,status,employee,service,category,resetFilters,refresh" aria-hidden="true"></span>
        </p>
        @if($selects !== [])
            <button type="button" class="button button--secondary button--sm std-toolbar__toggle" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-controls="std-filters">
                <x-ui.icon name="filter" size="16" />{{ __('manager_reports.std.filters.toggle') }}@if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
            </button>
        @endif
    </div>

    @if($customOpen)
        <form class="std-custom" wire:submit="applyCustomRange" novalidate>
            <x-ui.field :label="__('ui.fields.from')" for="std-range-from" name="customFrom">
                <input id="std-range-from" type="date" wire:model="customFrom" max="{{ $toolbar['today'] }}" @error('customFrom') aria-invalid="true" @enderror>
            </x-ui.field>
            <x-ui.field :label="__('ui.fields.to')" for="std-range-to" name="customTo">
                <input id="std-range-to" type="date" wire:model="customTo" max="{{ $toolbar['today'] }}" @error('customTo') aria-invalid="true" @enderror>
            </x-ui.field>
            <div class="std-custom__actions">
                <x-ui.button variant="ghost" wire:click="cancelCustomRange">{{ __('ui.actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="applyCustomRange">{{ __('ui.actions.apply') }}</x-ui.button>
            </div>
        </form>
    @endif

    @if($selects !== [])
        <div id="std-filters" class="std-filters" x-bind:class="{ 'is-open': open }">
            @foreach($selects as $select)
                <x-ui.field :label="$select['label']" :for="'std-filter-'.$select['name']" wire:key="std-filter-{{ $select['name'] }}">
                    <select id="std-filter-{{ $select['name'] }}" wire:model.live="{{ $select['name'] }}">
                        <option value="">{{ $select['all'] }}</option>
                        @foreach($select['options'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endforeach
            @if($filtered)
                <div class="std-filters__reset">
                    <x-ui.button variant="ghost" size="sm" icon="reset" wire:click="resetFilters">{{ __('manager_reports.std.filters.reset') }}</x-ui.button>
                </div>
            @endif
        </div>
    @endif
</section>
