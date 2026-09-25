{{--
    Day: one lane per team member (or room), a time axis in the branch's own
    time. Positions are minutes from the top of the axis, set as custom
    properties; every side is a logical property, so the grid mirrors itself in
    Arabic and Kurdish. On a phone the same bookings read as a time-ordered
    list first, and the grid scrolls inside its own card.
--}}
<section class="card card--flush bk-day" aria-label="{{ $heading }}" x-data="{ grid: false }">
    @if($rows === [])
        <x-ui.empty-state :compact="$board['lanes'] !== []" :icon="$hasFilters ? 'filter' : 'calendar'"
            :title="$hasFilters ? __('manager_booking.empty.filtered_title') : __('manager_booking.empty.day_title')">
            @if($hasFilters)
                <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
            @elseif($canCreate)
                <button class="button button--sm" type="button" wire:click="startBooking"><x-ui.icon name="plus" size="16" />{{ __('manager_booking.actions.new') }}</button>
            @endif
        </x-ui.empty-state>
    @endif

    @if($rows !== [])
        <ol class="bk-agenda" aria-label="{{ __('manager_booking.calendar.agenda') }}" x-bind:hidden="grid">
            @foreach($rows as $row)
                <li wire:key="agenda-{{ $row['uuid'] }}">
                    <button type="button" class="bk-agenda__item" data-tone="{{ $row['tone'] }}" @if($row['muted']) data-muted @endif wire:click="open('{{ $row['uuid'] }}')">
                        <span class="bk-agenda__time tabular" dir="ltr">{{ $row['time_label'] }}</span>
                        <span class="bk-agenda__body">
                            <strong>{{ $row['customer_name'] }}</strong>
                            <span class="cell-sub">{{ $row['services_label'] }}@if($row['staff_label'] !== '') · {{ $row['staff_label'] }}@endif</span>
                        </span>
                        <x-ui.status :value="$row['status']" :label="$row['status_label']" />
                    </button>
                </li>
            @endforeach
        </ol>
        <button type="button" class="button button--secondary button--sm bk-day__toggle" x-on:click="grid = ! grid" :aria-pressed="grid ? 'true' : 'false'">
            <x-ui.icon name="layout" size="16" /><span x-text="grid ? @js(__('manager_booking.calendar.show_list')) : @js(__('manager_booking.calendar.show_timeline'))">{{ __('manager_booking.calendar.show_timeline') }}</span>
        </button>
    @endif

    @if($board['lanes'] !== [])
        <div class="bk-grid-scroll" :class="{ 'is-open': grid }" tabindex="0" aria-label="{{ __('manager_booking.calendar.timeline') }}" data-bk-timeline data-bk-day="{{ $date }}">
            <div class="bk-grid" style="--bk-lanes: {{ count($board['lanes']) }}; --bk-minutes: {{ $board['minutes'] }};">
                <div class="bk-grid__corner" aria-hidden="true"></div>
                @foreach($board['lanes'] as $lane)
                    <div class="bk-grid__head" wire:key="head-{{ $lane['key'] }}" @if($lane['muted']) data-muted @endif>
                        <span class="bk-grid__name">{{ $lane['name'] }}</span>
                        <span class="bk-grid__count tabular">{{ $lane['count'] }}</span>
                    </div>
                @endforeach

                <div class="bk-grid__axis" aria-hidden="true">
                    @foreach($board['hours'] as $hour)
                        <span style="--at: {{ $hour['offset'] }};" dir="ltr">{{ $hour['label'] }}</span>
                    @endforeach
                </div>

                @foreach($board['lanes'] as $lane)
                    <div class="bk-lane" wire:key="lane-{{ $lane['key'] }}" role="group" aria-label="{{ $lane['name'] }}">
                        @foreach($board['closed'] as $closed)
                            <span class="bk-lane__closed" style="--at: {{ $closed['offset'] }}; --len: {{ $closed['length'] }};" aria-hidden="true"></span>
                        @endforeach
                        @foreach($board['blocks'][$lane['key']] ?? [] as $block)
                            <button type="button" class="bk-block" data-tone="{{ $block['tone'] }}" @if($block['muted']) data-muted @endif
                                    wire:key="block-{{ $lane['key'] }}-{{ $block['key'] }}"
                                    style="--at: {{ $block['offset'] }}; --len: {{ $block['length'] }}; --track: {{ $block['track'] }}; --tracks: {{ $block['tracks'] }};"
                                    wire:click="open('{{ $block['appointment'] }}')"
                                    title="{{ $block['time'] }} · {{ $block['title'] }} · {{ $block['service'] }} · {{ $block['status_label'] }}">
                                <span class="bk-block__time tabular" dir="ltr">{{ $block['time'] }}</span>
                                <strong class="bk-block__title">{{ $block['title'] }}</strong>
                                <span class="bk-block__meta">{{ $block['service'] }}@if($block['more'] > 0) <span class="bk-block__more">+{{ $block['more'] }}</span>@endif</span>
                                @if($lanes === 'rooms' && $block['staff'])<span class="bk-block__meta">{{ $block['staff'] }}</span>@endif
                            </button>
                        @endforeach
                        @if($board['now'] !== null)
                            <span class="bk-now" style="--at: {{ $board['now'] }};" aria-hidden="true"></span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @elseif($rows !== [])
        <p class="bk-day__nolanes muted">{{ __('manager_booking.calendar.no_rooms_used') }}</p>
    @endif
</section>
