{{--
    The bookings desk — day, week and list.

    Presentation only. Every appointment arrives already scoped by
    CalendarQuery and masked by AppointmentPresenter; geometry and labels come
    from CalendarBoard. Creating a booking and acting on one happen in the two
    child drawers below, which talk to this page through events that carry a
    uuid and nothing else (docs/15-BOOKING.md §1, docs/24 §11).
--}}
<div class="stack bk-page">
    <x-ui.page-header :title="__('manager_booking.title')" :subtitle="$heading">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('manager_booking.calendar.count', $total, ['count' => number_format($total)]) }}</span>
            @if($readOnly)
                <span class="badge" data-tone="warning"><x-ui.icon name="lock" size="14" />{{ __('manager_booking.calendar.read_only') }}</span>
            @endif
        </x-slot:meta>
        @if($canCreate)
            <x-slot:actions>
                <button class="button" type="button" wire:click="startBooking" wire:loading.attr="disabled" wire:target="startBooking">
                    <x-ui.icon name="plus" size="16" />{{ __('manager_booking.actions.new') }}
                </button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if($offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @elseif($readOnly)
        <x-ui.notice tone="warning" :message="__('manager_booking.calendar.paused')" />
    @endif

    @include('livewire.center.booking.partials.toolbar')

    <x-ui.notice tone="danger" :message="$error ?? ''" />

    <div class="bk-board" wire:poll.60s.visible="refreshBoard" wire:loading.class="is-refreshing"
         wire:target="move,goToday,setView,setLanes,setStatus,clearFilters,date,until,branchUuid,employeeUuid,serviceUuid,search,affected,gotoPage,nextPage,previousPage">
        @if($affected)
            @include('livewire.center.booking.partials.list', ['affectedList' => true])
        @elseif($view === 'day' && $board !== null)
            @include('livewire.center.booking.partials.day')
        @elseif($view === 'week')
            @include('livewire.center.booking.partials.week')
        @else
            @include('livewire.center.booking.partials.list', ['affectedList' => false])
        @endif
    </div>

    @if($composing && $canCreate)
        <livewire:center.booking.composer :branch="$composerBranch" :date="$date" :key="'composer-'.$composerRun" />
    @endif

    @if($viewing)
        <livewire:center.booking.appointment-panel :appointment="$viewing" :key="'booking-'.$viewing" />
    @endif
</div>
