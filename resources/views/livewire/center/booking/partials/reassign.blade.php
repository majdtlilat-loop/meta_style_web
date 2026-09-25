{{-- Same time, same price: only the reserved person changes (ReassignAppointmentEmployee). --}}
<section class="drawer-section bk-confirm" aria-labelledby="bk-p-reassign-title">
    <h3 id="bk-p-reassign-title">{{ __('manager_booking.panel.reassign_title') }}</h3>
    @if($candidates === [])
        <x-ui.notice tone="warning" :message="__('manager_booking.panel.reassign_nobody')" />
    @else
        <x-ui.field :label="__('manager_booking.composer.team_member')" for="bk-p-reassign">
            <select id="bk-p-reassign" wire:model="reassignTo">
                <option value="">{{ __('manager_booking.composer.any_available') }}</option>
                @foreach($candidates as $person)
                    <option value="{{ $person['uuid'] }}">{{ $person['name'] }}</option>
                @endforeach
            </select>
        </x-ui.field>
    @endif
    <div class="form-actions">
        <button class="button button--ghost" type="button" wire:click="show('detail')">{{ __('ui.actions.back') }}</button>
        @if($candidates !== [])
            <button class="button" type="button" wire:click="reassign" wire:loading.attr="data-loading" wire:target="reassign"><x-ui.icon name="check" size="16" />{{ __('manager_booking.panel.reassign_save') }}</button>
        @endif
    </div>
</section>
