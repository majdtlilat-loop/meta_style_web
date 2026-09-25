{{--
    Cancel one membership or package, right on its card. A reason is required;
    history stays and no money moves — a refund is a separate Payments refund.
--}}
@if($cancelling === $key)
    <form class="benefit-card__cancel" wire:submit="cancel">
        <x-ui.field :label="__('manager_benefits.panel.cancel_reason')" for="cancel-{{ md5($key) }}" name="cancelReason" required :help="__('manager_benefits.panel.cancel_help')">
            <input id="cancel-{{ md5($key) }}" type="text" maxlength="190" wire:model="cancelReason" autocomplete="off">
        </x-ui.field>
        <div class="cluster cluster--tight">
            <button class="button button--danger button--sm" type="submit" wire:loading.attr="data-loading" wire:target="cancel">{{ __('manager_benefits.panel.confirm_cancel') }}</button>
            <button class="button button--ghost button--sm" type="button" wire:click="stopCancel">{{ __('manager_benefits.panel.keep') }}</button>
        </div>
    </form>
@else
    <footer class="benefit-card__foot">
        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="startCancel('{{ $key }}')"><x-ui.icon name="x-circle" size="16" />{{ __('ui.actions.cancel') }}</button>
    </footer>
@endif
