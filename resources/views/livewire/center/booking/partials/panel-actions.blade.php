{{-- Only what AppointmentActions allowed. Each Action checks again on the server. --}}
@if(! $can['confirm'] && ! $canCheckIn && ! $can['complete'] && ! $can['reschedule'] && ! $can['no_show'] && ! $can['no_show_later'] && ! $can['issue_code'] && ! $can['cancel'])
    <span class="muted bk-hint">{{ $can['writable'] ? __('manager_booking.panel.closed') : __('manager_booking.panel.read_only') }}</span>
    <span class="drawer__spacer"></span>
    <button class="button button--secondary" type="button" wire:click="close">{{ __('ui.actions.close') }}</button>
@endif
@if($can['confirm'])
    <button class="button" type="button" wire:click="confirm" wire:loading.attr="data-loading" wire:target="confirm"><x-ui.icon name="check" size="16" />{{ __('manager_booking.actions.confirm') }}</button>
@endif
@if($canCheckIn)
    <button class="button button--secondary" type="button" wire:click="checkIn" wire:loading.attr="data-loading" wire:target="checkIn"><x-ui.icon name="user-check" size="16" />{{ __('manager_booking.actions.check_in') }}</button>
@endif
@if($can['complete'])
    <button class="button button--secondary" type="button" wire:click="complete" wire:loading.attr="data-loading" wire:target="complete"
            wire:confirm="{{ __('manager_booking.actions.complete_confirm') }}" data-confirm-title="{{ __('manager_booking.actions.complete') }}" data-confirm-label="{{ __('manager_booking.actions.complete') }}">
        <x-ui.icon name="check-circle" size="16" />{{ __('manager_booking.actions.complete') }}
    </button>
@endif
@if($can['reschedule'])
    <button class="button button--secondary" type="button" wire:click="show('move')"><x-ui.icon name="clock" size="16" />{{ __('manager_booking.actions.move') }}</button>
@endif
@if($can['no_show'])
    <button class="button button--secondary" type="button" wire:click="noShow" wire:loading.attr="data-loading" wire:target="noShow"
            wire:confirm="{{ __('manager_booking.actions.no_show_confirm') }}" data-confirm-title="{{ __('manager_booking.actions.no_show') }}" data-confirm-tone="danger" data-confirm-label="{{ __('manager_booking.actions.no_show') }}">
        <x-ui.icon name="user-x" size="16" />{{ __('manager_booking.actions.no_show') }}
    </button>
@elseif($can['no_show_later'])
    <button class="button button--secondary" type="button" disabled title="{{ __('manager_booking.actions.no_show_later') }}" aria-describedby="bk-noshow-hint"><x-ui.icon name="user-x" size="16" />{{ __('manager_booking.actions.no_show') }}</button>
    <span id="bk-noshow-hint" class="sr-only">{{ __('manager_booking.actions.no_show_later') }}</span>
@endif
@if($can['issue_code'])
    <button class="button button--ghost" type="button" wire:click="issueCode" wire:loading.attr="data-loading" wire:target="issueCode"
            wire:confirm="{{ __('manager_booking.code.issue_confirm') }}" data-confirm-title="{{ __('manager_booking.code.issue') }}" data-confirm-label="{{ __('manager_booking.code.issue') }}">
        <x-ui.icon name="key" size="16" />{{ __('manager_booking.code.issue') }}
    </button>
@endif
<span class="drawer__spacer"></span>
@if($can['cancel'])
    <button class="button button--danger-soft" type="button" wire:click="show('cancel')"><x-ui.icon name="x-circle" size="16" />{{ __('manager_booking.actions.cancel') }}</button>
@endif
