{{--
    Week: seven days from Saturday, each a column of that day's bookings in
    time order — grouped by each booking's OWN branch-local date, so a
    two-timezone center still lands every booking on the day the customer was
    told (docs/15-BOOKING.md §5). Stacked day cards on a phone.
--}}
<section class="bk-week" aria-label="{{ $heading }}">
    @foreach($week as $day)
        <div class="bk-week__day card" wire:key="day-{{ $day['date'] }}" @if($day['today']) data-today @endif>
            <header class="bk-week__head">
                <button type="button" class="bk-week__date" wire:click="openDay('{{ $day['date'] }}')" title="{{ __('manager_booking.calendar.open_day') }}">
                    <span class="bk-week__weekday">{{ $day['weekday'] }}</span>
                    <strong>{{ $day['label'] }}</strong>
                </button>
                @if($day['appointments'] !== [])
                    <span class="segmented__count tabular">{{ count($day['appointments']) }}</span>
                @endif
            </header>
            @forelse($day['appointments'] as $row)
                <button type="button" class="bk-card" data-tone="{{ $row['tone'] }}" @if($row['muted']) data-muted @endif wire:key="week-{{ $row['uuid'] }}" wire:click="open('{{ $row['uuid'] }}')">
                    <span class="bk-card__time tabular" dir="ltr">{{ $row['time_label'] }}</span>
                    <strong class="bk-card__title">{{ $row['customer_name'] }}</strong>
                    <span class="bk-card__meta">{{ $row['services_label'] }}</span>
                    @if($row['staff_label'] !== '')<span class="bk-card__meta">{{ $row['staff_label'] }}</span>@endif
                    <span class="sr-only">{{ $row['status_label'] }}</span>
                </button>
            @empty
                <p class="bk-week__empty">{{ __('manager_booking.calendar.nothing') }}</p>
            @endforelse
        </div>
    @endforeach
</section>
@if($rows === [] && $hasFilters)
    <x-ui.empty-state compact icon="filter" :title="__('manager_booking.empty.filtered_title')">
        <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
    </x-ui.empty-state>
@endif
