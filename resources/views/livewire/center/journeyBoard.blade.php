{{--
    Today's visits — the staff floor board. docs/16-JOURNEY-RESOURCES.md.

    NOT the queue: no numbers, no calling, no television. Cards, lanes and
    button flags come from JourneyBoardView; this template only lays them out.
    Refreshed by polling (a board a host glances at), never pushed.
--}}
<div class="stack visits-page" @if($isToday) wire:poll.15s.visible @endif>
    <x-ui.page-header :title="__('ui.manager_nav.items.board')">
        <x-slot:meta>
            @if($isToday)
                <span class="queue-live" title="{{ __('manager_visits.live_hint') }}"><span class="queue-live__dot" aria-hidden="true"></span>{{ __('manager_visits.live') }}</span>
            @endif
            @if($readOnly)
                <x-ui.status value="archived" :label="__('manager_queue.read_only')" />
            @endif
        </x-slot:meta>
        <x-slot:actions>
            @if($canQueue)
                <x-ui.button variant="secondary" icon="queue" :href="route('center.queue')" wire:navigate>{{ __('ui.manager_nav.items.queue') }}</x-ui.button>
            @endif
            @if($canWalkIn)
                <x-ui.button icon="user-plus" wire:click="openWalkIn">{{ __('manager_visits.actions.walk_in') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($readOnly && $offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    <div class="stat-grid visits-stats" role="group" aria-label="{{ __('manager_visits.stats.label') }}">
        @foreach(['not_arrived' => 'calendar', 'waiting' => 'clock', 'in_service' => 'scissors', 'completed' => 'check-circle', 'abandoned' => 'user-x'] as $key => $icon)
            <button type="button" class="stat stat--link visits-stat" wire:click="$set('group', '{{ $group === $key ? '' : $key }}')" aria-pressed="{{ $group === $key ? 'true' : 'false' }}">
                <span class="stat__icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span>
                <span class="stat__label">{{ __('manager_visits.lanes.'.$key) }}</span>
                <span class="stat__value">{{ number_format($counts[$key]) }}</span>
                @if($key === 'not_arrived' && $counts['late'] > 0)
                    <span class="stat__hint visits-stat__late">{{ __('manager_visits.stats.late', ['count' => $counts['late']]) }}</span>
                @elseif($key === 'waiting' && $counts['waiting_walk_ins'] > 0)
                    <span class="stat__hint">{{ trans_choice('manager_visits.stats.walk_ins', $counts['waiting_walk_ins'], ['count' => $counts['waiting_walk_ins']]) }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <form @class(['filter-bar', 'is-open' => $filtersOpen]) role="search" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}" data-collapsible>
        <div class="field filter-bar__search">
            <label for="visits-date">{{ __('ui.fields.date') }}</label>
            <input id="visits-date" type="date" wire:model.live="date" @if($date === '' && $today) x-data x-init="$nextTick(() => { if (! $el.value) $el.value = @js($today) })" @endif>
        </div>
        @if(count($branches) > 1)
            <div class="field">
                <label for="visits-branch">{{ __('ui.fields.branch') }}</label>
                <select id="visits-branch" wire:model.live="branch">
                    <option value="">{{ __('ui.fields.all_branches') }}</option>
                    @foreach($branches as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <button class="button button--secondary filter-bar__toggle" type="button" wire:click="$toggle('filtersOpen')" aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
        </button>
        @if($departments !== [])
            <div class="field">
                <label for="visits-department">{{ __('manager_queue.filters.department') }}</label>
                <select id="visits-department" wire:model.live="department">
                    <option value="">{{ __('manager_queue.filters.all_departments') }}</option>
                    @foreach($departments as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if($team !== [])
            <div class="field">
                <label for="visits-employee">{{ __('manager_visits.filters.team_member') }}</label>
                <select id="visits-employee" wire:model.live="employee">
                    <option value="">{{ __('manager_visits.filters.everyone') }}</option>
                    @foreach($team as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
            </div>
        @endif
    </form>

    {{-- The phone lane switcher is server state, so a poll can never snap it back. --}}
    <div class="queue-board visits-board" wire:loading.class="is-refreshing" wire:target="date,branch,department,employee,group,clearFilters">
        @if(count($lanes) > 1)
            <div class="segmented segmented--scroll queue-lane-switch" role="group" aria-label="{{ __('manager_visits.lanes.label') }}">
                @foreach($lanes as $key => $cards)
                    <button type="button" wire:click="$set('lane', '{{ $key }}')" aria-pressed="{{ $lane === $key ? 'true' : 'false' }}">
                        {{ __('manager_visits.lanes.'.$key) }}<span class="segmented__count">{{ count($cards) }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="queue-lanes" data-count="{{ count($lanes) }}">
            @foreach($lanes as $key => $cards)
                <section class="queue-lane" data-lane="{{ $key }}" data-active="{{ $lane === $key ? 'true' : 'false' }}" aria-labelledby="visits-lane-{{ $key }}">
                    <header class="queue-lane__head">
                        <h2 id="visits-lane-{{ $key }}">{{ __('manager_visits.lanes.'.$key) }}</h2>
                        <span class="queue-lane__count">{{ count($cards) }}</span>
                    </header>
                    <div class="queue-lane__body">
                        @forelse($cards as $card)
                            @include('livewire.center.journey.visit-card', ['card' => $card, 'readOnly' => $readOnly, 'canCheckout' => $canCheckout])
                        @empty
                            <p class="queue-lane__empty">{{ __('manager_visits.lanes.empty_'.$key) }}</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    <livewire:center.journey.visit-panel />
    <livewire:center.queue.walk-in-form />
</div>
