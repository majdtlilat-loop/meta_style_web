{{-- Date navigation, view switch, status switch and filters. No logic: every option list arrives scoped. --}}
<div class="bk-toolbar">
    <div class="bk-toolbar__dates" role="group" aria-label="{{ __('manager_booking.calendar.navigate') }}">
        <button class="icon-button icon-button--bordered" type="button" wire:click="move(-1)" aria-label="{{ __('manager_booking.calendar.previous.'.$view) }}" title="{{ __('manager_booking.calendar.previous.'.$view) }}">
            <x-ui.icon name="chevron-left" />
        </button>
        <button class="button button--secondary button--sm" type="button" wire:click="goToday" @disabled($isToday && $view === 'day')>{{ __('manager_booking.calendar.today') }}</button>
        <button class="icon-button icon-button--bordered" type="button" wire:click="move(1)" aria-label="{{ __('manager_booking.calendar.next.'.$view) }}" title="{{ __('manager_booking.calendar.next.'.$view) }}">
            <x-ui.icon name="chevron-right" />
        </button>
        <label class="sr-only" for="bk-date">{{ $view === 'list' ? __('manager_booking.calendar.from') : __('manager_booking.calendar.date') }}</label>
        <input id="bk-date" class="bk-toolbar__date" type="date" wire:model.live="date" required>
        @if($view === 'list')
            <span class="bk-toolbar__dash" aria-hidden="true">–</span>
            <label class="sr-only" for="bk-until">{{ __('manager_booking.calendar.to') }}</label>
            <input id="bk-until" class="bk-toolbar__date" type="date" wire:model.live="until" min="{{ $date }}">
        @endif
    </div>

    <div class="bk-toolbar__views">
        <div class="segmented" role="group" aria-label="{{ __('manager_booking.calendar.view') }}">
            @foreach(['day', 'week', 'list'] as $option)
                <button type="button" wire:click="setView('{{ $option }}')" aria-pressed="{{ $view === $option ? 'true' : 'false' }}">
                    <x-ui.icon :name="$option === 'list' ? 'list' : ($option === 'week' ? 'grid' : 'calendar')" size="16" />{{ __('manager_booking.calendar.views.'.$option) }}
                </button>
            @endforeach
        </div>
        @if($view === 'day' && $hasRooms && ! $affected)
            <div class="segmented" role="group" aria-label="{{ __('manager_booking.calendar.lanes_label') }}">
                <button type="button" wire:click="setLanes('team')" aria-pressed="{{ $lanes === 'team' ? 'true' : 'false' }}"><x-ui.icon name="users" size="16" />{{ __('manager_booking.calendar.lanes.team') }}</button>
                <button type="button" wire:click="setLanes('rooms')" aria-pressed="{{ $lanes === 'rooms' ? 'true' : 'false' }}"><x-ui.icon name="layers" size="16" />{{ __('manager_booking.calendar.lanes.rooms') }}</button>
            </div>
        @endif
    </div>
</div>

@unless($affected)
    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('manager_booking.filters.status') }}">
        <button type="button" wire:click="setStatus('')" aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}">{{ __('manager_booking.filters.all_statuses') }}<span class="segmented__count">{{ number_format($total) }}</span></button>
        @foreach($statusOptions as $option)
            <button type="button" wire:click="setStatus('{{ $option['value'] }}')" aria-pressed="{{ $statusFilter === $option['value'] ? 'true' : 'false' }}">{{ $option['label'] }}<span class="segmented__count">{{ number_format($option['count']) }}</span></button>
        @endforeach
    </div>
@endunless

<form class="filter-bar bk-filters" role="search" x-on:submit.prevent aria-label="{{ __('manager_booking.filters.label') }}" data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
    <div class="field filter-bar__search">
        <label for="bk-search">{{ __('manager_booking.filters.search') }}</label>
        <div class="search-input">
            <x-ui.icon name="search" />
            <input id="bk-search" type="search" wire:model.live.debounce.400ms="search" autocomplete="off" maxlength="80"
                   placeholder="{{ $canSearchContact ? __('manager_booking.filters.search_contact') : __('manager_booking.filters.search_name') }}">
        </div>
    </div>
    <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="bk-filter-employee">
        <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
        @if($hasFilters)<span class="badge" data-tone="primary">•</span>@endif
    </button>
    @if(count($branches) > 1)
        <div class="field">
            <label for="bk-filter-branch">{{ __('manager_booking.filters.branch') }}</label>
            <select id="bk-filter-branch" wire:model.live="branchUuid">
                <option value="all">{{ __('manager_booking.filters.all_branches') }}</option>
                @foreach($branches as $option)
                    <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="field">
        <label for="bk-filter-employee">{{ __('manager_booking.filters.employee') }}</label>
        <select id="bk-filter-employee" wire:model.live="employeeUuid">
            <option value="">{{ __('manager_booking.filters.everyone') }}</option>
            @foreach($team as $person)
                <option value="{{ $person['uuid'] }}">{{ $person['active'] ? $person['name'] : __('manager_booking.filters.inactive_person', ['name' => $person['name']]) }}</option>
            @endforeach
        </select>
    </div>
    @if($services !== [])
        <div class="field">
            <label for="bk-filter-service">{{ __('manager_booking.filters.service') }}</label>
            <select id="bk-filter-service" wire:model.live="serviceUuid">
                <option value="">{{ __('manager_booking.filters.all_services') }}</option>
                @foreach($services as $option)
                    <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </div>
    @endif
    @if($canSeeAffected)
        <div class="field bk-filters__switch">
            <label class="check-row" for="bk-filter-affected" title="{{ __('manager_booking.filters.affected_help') }}">
                <input id="bk-filter-affected" type="checkbox" class="switch" role="switch" wire:model.live="affected">
                {{ __('manager_booking.filters.affected') }}
            </label>
        </div>
    @endif
    @if($hasFilters)
        <div class="filter-bar__actions">
            <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
        </div>
    @endif
</form>
