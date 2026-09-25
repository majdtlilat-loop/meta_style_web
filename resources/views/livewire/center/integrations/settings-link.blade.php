{{--
    The WhatsApp entry in the Settings navigation. A locked link is a plain
    link (never wire:navigate) so the upgrade prompt can take the click, and
    without JavaScript it still lands on the page, which shows the offer.
--}}
@if($visible)
    <a href="{{ $href }}" @if($locked) data-upgrade-feature="whatsapp_booking" aria-haspopup="dialog" @else wire:navigate @endif>
        <x-ui.icon name="message" size="16" />{{ __('manager_whatsapp.title') }}
        @if($locked)
            <span class="badge" data-tone="neutral">{{ __('manager_whatsapp.link.locked') }}</span>
        @elseif($attention)
            <span class="badge" data-tone="warning">{{ __('manager_whatsapp.link.attention') }}</span>
        @endif
        <x-ui.icon name="chevron-right" size="14" class="settings-nav__chevron ui-icon--directional" />
    </a>
@else
    <span hidden></span>
@endif
