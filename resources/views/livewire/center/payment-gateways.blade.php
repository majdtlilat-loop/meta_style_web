{{--
    A branch's online payment gateways. docs/19-PAYMENTS.md §61.

    Credential inputs are always empty: nothing secret is ever loaded into this
    page, and there is no "show secret". Labels arrive translated from the
    component; the view decides nothing.
--}}
<div class="stack pos-page">
    <x-ui.page-header :title="__('Online payment gateways')">
        @if($canConfigure && ! $showForm)
            <x-slot:actions>
                <button class="button" type="button" wire:click="newAccount"><x-ui.icon name="plus" size="16" />{{ __('Configure a gateway') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if(! $owned && $offer !== null)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($denied)
        <x-ui.empty-state icon="lock" :title="__('manager_finance.denied.title')" :description="__('manager_pos.gateways.denied')" />
    @else
        @if($error !== '' && ! $showForm)<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
        @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif
        @if($listError !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $listError }}</p></div>@endif

        @if(count($branches) > 1)
            <div class="filter-bar">
                <div class="field">
                    <label for="gateway-branch">{{ __('Branch') }}</label>
                    <select id="gateway-branch" wire:model.live="branch">
                        @foreach($branches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        <div class="pos-gateways">
            <x-ui.card :title="__('manager_pos.gateways.accounts')" flush>
                @if($accounts === [])
                    <x-ui.empty-state compact icon="payments" :title="__('No gateway configured for this branch.')">
                        @if($canConfigure && ! $showForm)<button class="button button--sm" type="button" wire:click="newAccount">{{ __('Configure a gateway') }}</button>@endif
                    </x-ui.empty-state>
                @else
                    <ul class="row-list">
                        @foreach($accounts as $account)
                            <li class="row-list__item pos-gateway" wire:key="gateway-{{ $account['uuid'] }}">
                                <span class="row-list__icon" @if($account['enabled']) data-tone="success" @endif aria-hidden="true"><x-ui.icon name="credit-card" /></span>
                                <div class="row-list__body">
                                    <span class="cell-title">{{ $account['display_name'] }}</span>
                                    <span class="cell-sub">{{ $account['provider_name'] }} · {{ $account['environment_label'] }}@if($account['safe_identifier'] !== null) · <span dir="ltr">{{ $account['safe_identifier'] }}</span>@endif</span>
                                    <span class="cell-sub">{{ __('manager_pos.gateways.configured_by', ['name' => $account['configured_by'] ?? '—', 'time' => $account['configured_at_label']]) }}</span>
                                    <span class="cell-sub">{{ $account['supports_refunds'] ? __('manager_pos.gateways.refunds_yes') : __('manager_pos.gateways.refunds_no') }}</span>
                                </div>
                                <div class="cluster cluster--tight">
                                    @unless($account['configured'])<x-ui.status tone="warning" :label="__('manager_pos.gateways.not_configured')" :dot="false" />@endunless
                                    <x-ui.status :value="$account['state']" :label="$account['state_label']" />
                                    @if($canConfigure)
                                        <button class="button button--ghost button--sm" type="button" wire:click="editAccount('{{ $account['uuid'] }}')"><x-ui.icon name="key" size="14" />{{ __('manager_pos.gateways.update') }}</button>
                                    @endif
                                    @if($account['enabled'])
                                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="disable('{{ $account['uuid'] }}')" wire:confirm="{{ __('manager_pos.gateways.disable_confirm', ['name' => $account['display_name']]) }}" data-confirm-title="{{ __('manager_pos.gateways.disable') }}" data-confirm-tone="danger">{{ __('manager_pos.gateways.disable') }}</button>
                                    @elseif($canConfigure)
                                        <button class="button button--secondary button--sm" type="button" wire:click="enable('{{ $account['uuid'] }}')" wire:loading.attr="data-loading" wire:target="enable">{{ __('manager_pos.gateways.enable') }}</button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            @if($showForm && $canConfigure)
                <form class="card card--flush pos-gateway-form" wire:submit="configure" autocomplete="off" aria-labelledby="configure-title">
                    <header class="card__header">
                        <h2 id="configure-title">{{ $editing !== '' ? __('manager_pos.gateways.update_title') : __('Configure a gateway') }}</h2>
                        <button class="icon-button icon-button--sm" type="button" wire:click="closeForm" aria-label="{{ __('ui.actions.close') }}"><x-ui.icon name="close" /></button>
                    </header>
                    <div class="card__body stack stack--sm">
                        @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
                        <x-ui.field :label="__('Provider')" for="gateway-provider" name="provider">
                            <select id="gateway-provider" wire:model.live="provider" @disabled($editing !== '')>
                                @foreach($providers as $option)
                                    <option value="{{ $option['code'] }}" @disabled(! $option['available'])>{{ $option['name'] }}@unless($option['available']) — {{ __('not available yet') }}@endunless</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <fieldset class="field">
                            <legend>{{ __('Environment') }}</legend>
                            <div class="segmented" role="radiogroup">
                                @foreach($environments as $option)
                                    <label @class(['is-active' => $environment === $option['value']])><input class="sr-only" type="radio" value="{{ $option['value'] }}" wire:model.live="environment">{{ $option['label'] }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <x-ui.field :label="__('Account name')" for="gateway-name" name="displayName" required>
                            <input id="gateway-name" type="text" wire:model="displayName" maxlength="120" required>
                        </x-ui.field>
                        @if($selectedProvider !== null && $selectedProvider['fields'] !== [])
                            <p class="field-help"><x-ui.icon name="lock" size="14" /> {{ $editing !== '' ? __('manager_pos.gateways.secrets_replace') : __('manager_pos.gateways.secrets_hint') }}</p>
                            @foreach($selectedProvider['fields'] as $field)
                                {{-- Write-only: never pre-filled. --}}
                                <x-ui.field :label="$field['label']" for="credential-{{ $field['name'] }}" name="credentials.{{ $field['name'] }}" required>
                                    <input id="credential-{{ $field['name'] }}" type="password" dir="ltr" wire:model="credentials.{{ $field['name'] }}" autocomplete="new-password" required>
                                </x-ui.field>
                            @endforeach
                        @endif
                    </div>
                    <footer class="card__footer cluster">
                        <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('Cancel') }}</button>
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="configure"><x-ui.icon name="save" size="16" />{{ __('Save gateway') }}</button>
                    </footer>
                </form>
            @endif
        </div>
    @endif
</div>
