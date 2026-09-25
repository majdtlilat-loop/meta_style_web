{{-- The connection: identifiers that are not secret, and each credential as configured / not configured. --}}
<x-ui.card :title="__('manager_whatsapp.connection.title')" class="wa-connection">
    @if($canManage)
        <x-slot:actions>
            @if($account['enabled'])
                <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="turnOff"
                    wire:confirm="{{ __('manager_whatsapp.actions.turn_off_confirm') }}" data-confirm-title="{{ __('manager_whatsapp.actions.turn_off_title') }}" data-confirm-tone="danger"
                    wire:loading.attr="data-loading" wire:target="turnOff">
                    <x-ui.icon name="power" size="16" />{{ __('manager_whatsapp.actions.turn_off') }}
                </button>
            @else
                <button class="button button--secondary button--sm" type="button" wire:click="turnOn" wire:loading.attr="data-loading" wire:target="turnOn" @disabled(! $account['configured'])>
                    <x-ui.icon name="power" size="16" />{{ __('manager_whatsapp.actions.turn_on') }}
                </button>
            @endif
        </x-slot:actions>
    @endif

    @if($account['credentials_state'] === 'unreadable')
        <div class="notice" data-tone="warning" role="status"><x-ui.icon name="alert-triangle" /><p>{{ __('manager_whatsapp.credentials.unreadable') }}</p></div>
    @endif

    <dl class="summary-list wa-summary">
        <div>
            <dt>{{ __('manager_whatsapp.connection.number') }}</dt>
            <dd><span class="tabular" dir="ltr">{{ $account['number'] ?? '—' }}</span></dd>
        </div>
        <div>
            <dt>{{ __('manager_whatsapp.connection.display_name') }}</dt>
            <dd>{{ $account['display_name'] }}</dd>
        </div>
        <div>
            <dt>{{ __('manager_whatsapp.connection.provider') }}</dt>
            <dd>{{ $account['provider'] }}</dd>
        </div>
        <div>
            <dt>{{ __('manager_whatsapp.connection.phone_number_id') }}</dt>
            <dd><span class="mono" dir="ltr">{{ $account['phone_number_id'] ?? '—' }}</span></dd>
        </div>
        @if($account['business_account_id'])
            <div>
                <dt>{{ __('manager_whatsapp.connection.business_account_id') }}</dt>
                <dd><span class="mono" dir="ltr">{{ $account['business_account_id'] }}</span></dd>
            </div>
        @endif
        <div>
            <dt>{{ __('manager_whatsapp.connection.booking') }}</dt>
            <dd><x-ui.status :tone="$account['enabled'] ? 'success' : 'neutral'" :label="__($account['enabled'] ? 'manager_whatsapp.status.on' : 'manager_whatsapp.status.off')" /></dd>
        </div>
        <div class="wa-summary__stacked">
            <dt>{{ __('manager_whatsapp.connection.credentials') }}</dt>
            <dd>
                <ul class="wa-credentials">
                    @foreach($account['credentials'] as $credential)
                        <li>
                            <span>{{ $credential['label'] }}</span>
                            <x-ui.status :tone="$credential['set'] ? 'success' : 'warning'" :dot="false" :label="__($credential['set'] ? 'manager_whatsapp.credentials.configured' : 'manager_whatsapp.credentials.not_configured')" />
                        </li>
                    @endforeach
                </ul>
            </dd>
        </div>
        <div>
            <dt>{{ __('manager_whatsapp.connection.configured') }}</dt>
            <dd>{{ $account['configured_label'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('manager_whatsapp.connection.last_sent') }}</dt>
            <dd>
                @if($account['last_outbound'])
                    <span class="wa-inline">
                        <x-ui.status :tone="$account['last_outbound']['tone']" :label="$account['last_outbound']['label']" />
                        <span class="cell-sub">{{ $account['last_outbound']['time'] }}</span>
                        @if($account['last_outbound']['code'])<span class="mono" dir="ltr">{{ $account['last_outbound']['code'] }}</span>@endif
                    </span>
                @else
                    —
                @endif
            </dd>
        </div>
        @if($account['last_error'])
            <div>
                <dt>{{ __('manager_whatsapp.connection.last_error') }}</dt>
                <dd><span class="wa-inline"><span class="mono" dir="ltr">{{ $account['last_error']['code'] }}</span><span class="cell-sub">{{ $account['last_error']['time'] }}</span></span></dd>
            </div>
        @endif
    </dl>
</x-ui.card>
