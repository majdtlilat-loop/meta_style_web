{{--
    A customer's bookings — upcoming, then history. Branch-local booked times
    from the Booking presenter; changes happen on the calendar, not here.
--}}
<div class="stack">
    <x-ui.card :title="__('manager_customers.bookings.upcoming')" flush>
        <x-slot:actions>
            <a class="button button--ghost button--sm" href="{{ $calendarUrl }}" wire:navigate><x-ui.icon name="calendar" size="16" />{{ __('manager_customers.bookings.open_calendar') }}</a>
        </x-slot:actions>
        @if($upcoming === [])
            <x-ui.empty-state compact icon="calendar" :title="__('manager_customers.bookings.none_upcoming')" />
        @else
            @include('livewire.center.customers.partials.appointment-table', ['rows' => $upcoming, 'caption' => __('manager_customers.bookings.upcoming')])
        @endif
    </x-ui.card>

    <x-ui.card :title="__('manager_customers.bookings.history')" flush>
        @if($past === [])
            <x-ui.empty-state compact icon="history" :title="__('manager_customers.bookings.none_past')" />
        @else
            @include('livewire.center.customers.partials.appointment-table', ['rows' => $past, 'caption' => __('manager_customers.bookings.history')])
        @endif
    </x-ui.card>
</div>
