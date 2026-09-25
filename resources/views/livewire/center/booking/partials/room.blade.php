{{-- Same service, same time, same price: only the reserved room or device changes (ReassignAppointmentResource). --}}
<section class="drawer-section bk-confirm" aria-labelledby="bk-p-room-title">
    <h3 id="bk-p-room-title">{{ __('manager_booking.panel.room_title') }}</h3>
    @if($rooms === [])
        <x-ui.notice tone="warning" :message="__('manager_booking.panel.room_nothing')" />
    @else
        <div class="form-grid">
            <x-ui.field :label="__('manager_booking.panel.room_current')" for="bk-p-room-from" name="roomFrom" required>
                <select id="bk-p-room-from" wire:model.live="roomFrom">
                    <option value="">{{ __('manager_booking.panel.room_pick') }}</option>
                    @foreach($rooms as $room)
                        <option value="{{ $room['uuid'] }}">{{ $room['name'] }} · {{ $room['type'] }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_booking.panel.room_new')" for="bk-p-room-to" name="roomTo" required>
                <select id="bk-p-room-to" wire:model="roomTo" @disabled($roomOptions === [])>
                    <option value="">{{ __('manager_booking.panel.room_pick') }}</option>
                    @foreach($roomOptions as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
                @if($roomFrom !== '' && $roomOptions === [])<p class="field-help warning-text">{{ __('manager_booking.panel.room_none_other') }}</p>@endif
            </x-ui.field>
        </div>
    @endif
    <div class="form-actions">
        <button class="button button--ghost" type="button" wire:click="show('detail')">{{ __('ui.actions.back') }}</button>
        @if($rooms !== [])
            <button class="button" type="button" wire:click="changeRoom" wire:loading.attr="data-loading" wire:target="changeRoom" @disabled($roomOptions === [])><x-ui.icon name="check" size="16" />{{ __('manager_booking.panel.room_save') }}</button>
        @endif
    </div>
</section>
