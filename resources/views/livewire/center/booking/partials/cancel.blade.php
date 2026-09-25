{{-- Cancelling releases the time at once (docs/15 §9). A running visit ends with it (CancelVisit). --}}
<section class="drawer-section bk-confirm" aria-labelledby="bk-p-cancel">
    <h3 id="bk-p-cancel">{{ __('manager_booking.panel.cancel_title') }}</h3>
    <p class="muted">{{ $visit === 'active' ? __('manager_booking.panel.cancel_visit_body') : __('manager_booking.panel.cancel_body') }}</p>
    <x-ui.field :label="__('manager_booking.panel.reason')" for="bk-p-reason" name="cancelReason" :help="__('ui.states.optional')">
        <textarea id="bk-p-reason" rows="2" maxlength="190" wire:model="cancelReason"></textarea>
    </x-ui.field>
    <div class="form-actions">
        <button class="button button--ghost" type="button" wire:click="show('detail')">{{ __('manager_booking.panel.keep') }}</button>
        <button class="button button--danger" type="button" wire:click="cancel" wire:loading.attr="data-loading" wire:target="cancel"><x-ui.icon name="x-circle" size="16" />{{ __('manager_booking.actions.cancel') }}</button>
    </div>
</section>
