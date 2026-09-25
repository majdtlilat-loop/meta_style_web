{{--
    One booking. Everything shown arrived through CalendarQuery::find() (scope)
    and AppointmentPresenter (masking); every button is one AppointmentActions
    allowed, and every Action re-checks it. The verification code is
    REGENERATED, never shown again: it is stored as a keyed digest, so the only
    honest action is a new one, which retires the old (docs/24 §§8, 11).
--}}
<div>
@if($booking === null)
    <x-ui.drawer :title="__('manager_booking.panel.missing_title')" close="close">
        <x-ui.empty-state icon="calendar" :title="__('manager_booking.panel.missing_title')" />
    </x-ui.drawer>
@else
    <x-ui.drawer :title="$booking['customer_name']" :description="$booking['date_label'].' · '.$booking['time_label']" close="close" size="lg">
        <div class="stack bk-panel">
            <div class="cluster cluster--tight">
                <x-ui.status :value="$booking['status']" :label="$booking['status_label']" />
                @if($booking['reference'])<span class="badge" dir="ltr">{{ $booking['reference'] }}</span>@endif
                <span class="badge">{{ $booking['source_label'] }}</span>
                @if($visit === 'active')
                    <span class="badge" data-tone="info"><x-ui.icon name="user-check" size="14" />{{ __('manager_booking.panel.in_visit') }}</span>
                @elseif($visit === 'completed')
                    <span class="badge" data-tone="success">{{ __('manager_booking.panel.visit_done') }}</span>
                @endif
                @if($bookingLocked)<span class="badge" data-tone="warning"><x-ui.icon name="lock" size="14" />{{ __('manager_booking.calendar.read_only') }}</span>@endif
            </div>

            <x-ui.notice :message="$notice" dismiss="dismissNotice" />
            <x-ui.notice tone="danger" :message="$error" dismiss="dismissNotice" />

            @if($issuedCode !== '')
                <div class="secret-card" role="status">
                    <div class="secret-card__head">
                        <span class="secret-card__icon" aria-hidden="true"><x-ui.icon name="key" /></span>
                        <div>
                            <strong>{{ __('manager_booking.code.new_title') }}</strong>
                            <p>{{ __('manager_booking.code.once') }}</p>
                        </div>
                    </div>
                    <div class="copy-field">
                        <code dir="ltr" class="bk-code">{{ $issuedCode }}</code>
                        <button class="button button--secondary button--sm" type="button" data-copy="{{ $issuedCode }}" data-copied="{{ __('ui.actions.copied') }}"><x-ui.icon name="copy" size="16" />{{ __('ui.actions.copy') }}</button>
                    </div>
                    <button class="text-button" type="button" wire:click="clearCode">{{ __('manager_booking.code.done') }}</button>
                </div>
            @endif

            @if($mode === 'move')
                <livewire:center.booking.reschedule-form :appointment="$appointment" :key="'move-'.$appointment" />
            @elseif($mode === 'cancel')
                @include('livewire.center.booking.partials.cancel')
            @elseif($mode === 'reassign')
                @include('livewire.center.booking.partials.reassign')
            @elseif($mode === 'room')
                @include('livewire.center.booking.partials.room')
            @else
                @include('livewire.center.booking.partials.panel-detail')
            @endif
        </div>

        @if($mode === 'detail')
            <x-slot:footer>
                @include('livewire.center.booking.partials.panel-actions')
            </x-slot:footer>
        @endif
    </x-ui.drawer>
@endif
</div>
