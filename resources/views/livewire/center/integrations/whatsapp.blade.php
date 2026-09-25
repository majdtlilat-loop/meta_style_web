{{--
    Manager → Settings → WhatsApp. docs/25-WHATSAPP.md §21.

    Every value arrives prepared by App\Livewire\Center\Integrations\WhatsApp
    and WhatsAppStatusView: labels translated, instants on the center's clock.
    No credential ever reaches this template — only "configured" booleans.
    The setup check reads what Meta Style recorded; it never claims a live
    provider test (the Cloud API adapter is not live-verified, §16).
--}}
<div class="stack wa-page">
    <x-ui.page-header :title="__('manager_whatsapp.title')">
        @if($account)
            <x-slot:meta>
                <x-ui.status :tone="$account['configured'] ? 'success' : 'neutral'" :label="__($account['configured'] ? 'manager_whatsapp.status.connected' : 'manager_whatsapp.status.not_connected')" />
                <x-ui.status :tone="$account['enabled'] ? 'success' : 'neutral'" :label="__($account['enabled'] ? 'manager_whatsapp.status.booking_on' : 'manager_whatsapp.status.booking_off')" />
            </x-slot:meta>
        @endif
        @if($owned && ($account || $canManage))
            <x-slot:actions>
                @if($account)
                    <button class="button button--secondary" type="button" wire:click="checkSetup" wire:loading.attr="data-loading" wire:target="checkSetup">
                        <x-ui.icon name="refresh" size="16" />{{ __('manager_whatsapp.actions.check') }}
                    </button>
                @endif
                @if($canManage)
                    <button class="button" type="button" wire:click="$dispatch('whatsapp-connection-open')">
                        <x-ui.icon :name="$account ? 'edit' : 'plus'" size="16" />{{ __($account ? 'manager_whatsapp.actions.edit' : 'manager_whatsapp.actions.connect') }}
                    </button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    @if(! $owned)
        @if($offer)
            <x-manager.feature-locked :offer="$offer" />
        @else
            <x-ui.empty-state icon="lock" :title="__('manager_whatsapp.inactive')" />
        @endif
    @elseif(! $account)
        <x-ui.empty-state icon="message" :title="__('manager_whatsapp.empty.title')" :description="$canManage ? null : __('manager_whatsapp.empty.view_only')">
            @if($canManage)
                <button class="button" type="button" wire:click="$dispatch('whatsapp-connection-open')"><x-ui.icon name="plus" size="16" />{{ __('manager_whatsapp.actions.connect') }}</button>
            @endif
        </x-ui.empty-state>
    @endif

    @if($account)
        <div class="wa-grid">
            <div class="stack">
                @include('livewire.center.integrations.partials.connection')
                @if($webhook)
                    @include('livewire.center.integrations.partials.webhook')
                @endif
            </div>
            @if($checks)
                @include('livewire.center.integrations.partials.checks')
            @endif
        </div>
    @endif

    @if($owned)
        <div class="wa-grid">
            <div class="stack">
                <livewire:center.integrations.booking-confirmations wire:key="wa-confirmations" />
                <livewire:center.integrations.assistant-settings wire:key="wa-assistant" />
            </div>
            @include('livewire.center.integrations.partials.flow')
        </div>
    @endif

    @if($canManage)
        <livewire:center.integrations.connection-form wire:key="wa-connection-form" />
    @endif
</div>
