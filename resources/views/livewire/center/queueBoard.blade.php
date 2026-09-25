{{--
    The reception queue. docs/17-QUEUE.md §§15, 21.

    Every card, lane, figure and button flag arrives from QueueBoardView — this
    template only lays them out. Logical CSS properties throughout so the same
    markup is right in Arabic and Kurdish (resources/css/manager/queue.css).

    Polls every 5 s while it shows today (§15). The walk-in form, the ticket
    drawer and the desk/screen setup are child components, so a poll never
    wipes something half-typed.
--}}
<div class="stack queue-page" @if($tab === 'board' && $isToday && ! $readOnly) wire:poll.5s.visible @endif>
    <x-ui.page-header :title="__('ui.manager_nav.items.queue')">
        <x-slot:meta>
            @if($tab === 'board' && $isToday && ! $readOnly)
                <span class="queue-live" title="{{ __('manager_queue.live_hint') }}"><span class="queue-live__dot" aria-hidden="true"></span>{{ __('manager_queue.live') }}</span>
            @endif
            @if($readOnly)
                <x-ui.status value="archived" :label="__('manager_queue.read_only')" />
            @endif
        </x-slot:meta>
        @if(! $readOnly && $tab === 'board')
            <x-slot:actions>
                @if($flags['call'])
                    <x-ui.button variant="secondary" icon="megaphone" wire:click="callNext" wire:loading.attr="data-loading" wire:target="callNext"
                        title="{{ $branch === '' && count($branches) > 1 ? __('manager_queue.notices.choose_branch') : __('manager_queue.call_next_hint') }}">
                        {{ __('manager_queue.actions.call_next') }}
                    </x-ui.button>
                @endif
                @if($flags['walk_in'])
                    <x-ui.button icon="user-plus" wire:click="openWalkIn">{{ __('manager_queue.actions.walk_in') }}</x-ui.button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if($readOnly && $offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    <div class="queue-outcome" role="status" aria-live="polite">
        <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />
        @if($issuedTicket !== '' && $flags['print'])
            <a class="button button--secondary button--sm" href="{{ route('center.queue.ticket', ['uuid' => $issuedTicket]) }}" target="_blank" rel="noopener">
                <x-ui.icon name="print" size="16" />{{ __('manager_queue.actions.print_number', ['number' => $issuedNumber]) }}
            </a>
        @endif
    </div>

    @if($flags['setup'])
        <div class="tabs" role="tablist" aria-label="{{ __('ui.manager_nav.items.queue') }}">
            <button type="button" role="tab" wire:click="setTab('board')" aria-selected="{{ $tab === 'board' ? 'true' : 'false' }}"><x-ui.icon name="queue" size="16" />{{ __('manager_queue.tabs.board') }}</button>
            <button type="button" role="tab" wire:click="setTab('setup')" aria-selected="{{ $tab === 'setup' ? 'true' : 'false' }}"><x-ui.icon name="monitor" size="16" />{{ __('manager_queue.tabs.setup') }}</button>
        </div>
    @endif

    @if($tab === 'setup')
        <div class="stack">
            <livewire:center.queue.service-points />
            <livewire:center.queue.displays />
        </div>
    @else
        <div class="stat-grid queue-stats" aria-label="{{ __('manager_queue.stats.label') }}">
            <x-ui.stat :label="__('manager_queue.stats.waiting')" :value="number_format($summary['waiting'])" icon="clock" :hint="$summary['longest_wait'] !== null ? __('manager_queue.stats.longest', ['minutes' => $summary['longest_wait']]) : null" />
            <x-ui.stat :label="__('manager_queue.stats.called')" :value="number_format($summary['called'])" icon="megaphone" />
            <x-ui.stat :label="__('manager_queue.stats.serving')" :value="number_format($summary['serving'])" icon="scissors" />
            <x-ui.stat :label="__('manager_queue.stats.average_wait')" :value="$summary['average_wait'] === null ? '—' : __('manager_queue.minutes', ['minutes' => $summary['average_wait']])" icon="history" :hint="__('manager_queue.stats.average_hint')" />
            <x-ui.stat :label="__('manager_queue.stats.served')" :value="number_format($summary['completed'])" icon="check-circle" :hint="$summary['cancelled'] > 0 ? __('manager_queue.stats.cancelled', ['count' => $summary['cancelled']]) : null" />
        </div>

        <form @class(['filter-bar', 'queue-filters', 'is-open' => $filtersOpen]) role="search" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}" data-collapsible>
            <div class="field filter-bar__search">
                <label for="queue-date">{{ __('ui.fields.date') }}</label>
                <input id="queue-date" type="date" wire:model.live="date" @if($today) max="{{ $today }}" @endif @if($date === '' && $today) x-data x-init="$nextTick(() => { if (! $el.value) $el.value = @js($today) })" @endif>
            </div>
            @if(count($branches) > 1)
                <div class="field">
                    <label for="queue-branch">{{ __('ui.fields.branch') }}</label>
                    <select id="queue-branch" wire:model.live="branch">
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
                    <label for="queue-department">{{ __('manager_queue.filters.department') }}</label>
                    <select id="queue-department" wire:model.live="department">
                        <option value="">{{ __('manager_queue.filters.all_departments') }}</option>
                        @foreach($departments as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($points !== [])
                <div class="field">
                    <label for="queue-destination">{{ __('manager_queue.filters.destination') }}</label>
                    <select id="queue-destination" wire:model.live="servicePoint">
                        <option value="">{{ __('manager_queue.filters.any_destination') }}</option>
                        @foreach($points as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                @if($flags['call'] && ! $readOnly)
                    <div class="field queue-desk">
                        <label for="queue-desk">{{ __('manager_queue.filters.desk') }}</label>
                        <select id="queue-desk" wire:model.live="desk" title="{{ __('manager_queue.filters.desk_hint') }}">
                            <option value="">{{ __('manager_queue.filters.no_desk') }}</option>
                            @foreach($points as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endif
            @if($hasFilters)
                <div class="filter-bar__actions">
                    <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
                </div>
            @endif
        </form>

        @if($pending !== [])
            <section class="card card--flush queue-pending" aria-labelledby="queue-pending-title">
                <header class="card__header">
                    <div>
                        <h2 id="queue-pending-title">{{ __('manager_queue.pending.title') }}</h2>
                    </div>
                </header>
                <ul class="queue-pending__list">
                    @foreach($pending as $row)
                        <li wire:key="pending-{{ $row['stage_uuid'] }}">
                            <div class="queue-pending__who">
                                <strong>{{ $row['customer'] ?? __('manager_queue.card.guest') }}</strong>
                                <span class="cell-sub">{{ $row['service'] }}@if($row['employee']) · {{ $row['employee'] }}@endif</span>
                            </div>
                            <span class="queue-pending__time">{{ __('manager_queue.pending.arrived', ['time' => $row['arrived'] ?? '—']) }}@if($row['waiting_minutes'] !== null) · {{ __('manager_queue.minutes', ['minutes' => $row['waiting_minutes']]) }}@endif</span>
                            <x-ui.button size="sm" icon="ticket" wire:click="issue('{{ $row['stage_uuid'] }}')" wire:loading.attr="data-loading" wire:target="issue('{{ $row['stage_uuid'] }}')">{{ __('manager_queue.actions.issue') }}</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The phone lane switcher is server state, so a 5-second poll can never snap it back. --}}
        <div class="queue-board" wire:loading.class="is-refreshing" wire:target="date,branch,department,servicePoint,clearFilters">
            <div class="segmented segmented--scroll queue-lane-switch" role="group" aria-label="{{ __('manager_queue.lanes.label') }}">
                @foreach($lanes as $key => $cards)
                    <button type="button" wire:click="$set('lane', '{{ $key }}')" aria-pressed="{{ $lane === $key ? 'true' : 'false' }}">
                        {{ __('manager_queue.lanes.'.$key) }}<span class="segmented__count">{{ count($cards) }}</span>
                    </button>
                @endforeach
            </div>

            <div class="queue-lanes">
                @foreach($lanes as $key => $cards)
                    <section class="queue-lane" data-lane="{{ $key }}" data-active="{{ $lane === $key ? 'true' : 'false' }}" aria-labelledby="queue-lane-{{ $key }}">
                        <header class="queue-lane__head">
                            <h2 id="queue-lane-{{ $key }}">{{ __('manager_queue.lanes.'.$key) }}</h2>
                            <span class="queue-lane__count">{{ count($cards) }}</span>
                        </header>
                        <div class="queue-lane__body">
                            @forelse($cards as $card)
                                @include('livewire.center.queue.ticket-card', ['card' => $card, 'readOnly' => $readOnly])
                            @empty
                                <p class="queue-lane__empty">{{ __('manager_queue.lanes.empty_'.$key) }}</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @endif

    <livewire:center.queue.ticket-panel />
    <livewire:center.queue.walk-in-form />
</div>
