{{--
    Cloned by resources/js/platform/theme.js for every `wire:confirm`: a native
    <dialog>, so focus is trapped, Escape cancels and the page behind is inert.
    The strings are rendered here, in the viewer's language.
--}}
<template id="ms-confirm-template">
    <dialog class="ms-dialog" aria-labelledby="ms-confirm-title" aria-describedby="ms-confirm-message">
        <div class="modal__body">
            <span class="modal__icon" data-confirm-icon><x-ui.icon name="alert-triangle" /></span>
            <h2 id="ms-confirm-title" data-confirm-title>{{ __('ui.confirm.title') }}</h2>
            <p id="ms-confirm-message" data-confirm-message></p>
        </div>
        <div class="modal__footer">
            <button type="button" class="button button--secondary" data-confirm-cancel>{{ __('ui.confirm.cancel') }}</button>
            <button type="button" class="button" data-confirm-accept>{{ __('ui.confirm.confirm') }}</button>
        </div>
    </dialog>
</template>
