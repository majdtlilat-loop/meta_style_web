@php
    $kind = $panel !== null ? explode(':', $panel, 2)[0] : null;
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_currencies.title')">
        <x-slot:actions>
            <button class="button" type="button" wire:click="openPanel('create')"><x-ui.icon name="plus" size="16" />{{ __('sadmin_currencies.add') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />

    <div class="table-shell table-shell--stack">
        <table>
            <caption class="sr-only">{{ __('sadmin_currencies.title') }}</caption>
            <thead><tr>
                <th scope="col">{{ __('sadmin_currencies.table.currency') }}</th>
                <th scope="col">{{ __('sadmin_currencies.table.symbol') }}</th>
                <th scope="col" class="numeric">{{ __('sadmin_currencies.table.decimals') }}</th>
                <th scope="col">{{ __('sadmin_currencies.table.used_by') }}</th>
                <th scope="col">{{ __('sadmin_currencies.table.centers') }}</th>
                <th scope="col">{{ __('sadmin_currencies.table.status') }}</th>
                <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_currencies.table.actions') }}</span></th>
            </tr></thead>
            <tbody>
                @foreach($currencies as $currency)
                    @php $use = $usage[$currency->code]; @endphp
                    <tr wire:key="currency-{{ $currency->code }}">
                        <td data-label="{{ __('sadmin_currencies.table.currency') }}" data-primary>
                            <span class="cell-title"><span dir="ltr" class="mono">{{ $currency->code }}</span> · {{ $currency->name->get() }}</span>
                            @if($currency->is_default)<span class="cell-sub"><span class="badge" data-tone="primary">{{ __('sadmin_currencies.default') }}</span></span>@endif
                        </td>
                        <td data-label="{{ __('sadmin_currencies.table.symbol') }}" dir="ltr">{{ $currency->symbol }}</td>
                        <td data-label="{{ __('sadmin_currencies.table.decimals') }}" class="numeric">{{ $currency->decimals }}</td>
                        <td data-label="{{ __('sadmin_currencies.table.used_by') }}">
                            {{ trans_choice('sadmin_currencies.plans_count', $use['plans'], ['count' => $use['plans']]) }} ·
                            {{ trans_choice('sadmin_currencies.invoices_count', $use['invoices'], ['count' => $use['invoices']]) }} ·
                            {{ trans_choice('sadmin_currencies.centers_count', $use['centers'], ['count' => $use['centers']]) }}
                        </td>
                        <td data-label="{{ __('sadmin_currencies.table.centers') }}">
                            @if(in_array($currency->code, $centerSupported, true))
                                <x-ui.status value="enabled" tone="success" :label="__('sadmin_currencies.center_ready')" :dot="false" />
                            @else
                                <span class="muted">{{ __('sadmin_currencies.billing_only') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ __('sadmin_currencies.table.status') }}">
                            <x-ui.status :value="$currency->is_enabled ? 'enabled' : 'disabled'" :label="$currency->is_enabled ? __('sadmin_currencies.enabled_label') : __('sadmin_currencies.disabled_label')" />
                        </td>
                        <td class="actions">
                            <button class="button button--secondary button--sm" type="button" wire:click="openPanel('edit:{{ $currency->code }}')">{{ __('ui.actions.edit') }}</button>
                            <details class="dropdown" data-popover>
                                <summary class="icon-button icon-button--sm" aria-label="{{ __('sadmin_currencies.more', ['code' => $currency->code]) }}"><x-ui.icon name="more" /></summary>
                                <div class="dropdown__panel" role="menu">
                                    @if(! $currency->is_default && $currency->is_enabled)
                                        <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('default:{{ $currency->code }}')"><x-ui.icon name="star" />{{ __('sadmin_currencies.make_default') }}</button>
                                    @endif
                                    @unless($currency->is_default)
                                        <button class="menu-item {{ $currency->is_enabled ? 'menu-item--danger' : '' }}" role="menuitem" type="button" wire:click="toggle('{{ $currency->code }}')"
                                            @if($currency->is_enabled) wire:confirm="{{ __('sadmin_currencies.disable_confirm', ['code' => $currency->code]) }}" data-confirm-title="{{ __('sadmin_currencies.disable') }}" data-confirm-tone="danger" @endif>
                                            <x-ui.icon :name="$currency->is_enabled ? 'pause' : 'play'" />{{ $currency->is_enabled ? __('sadmin_currencies.disable') : __('sadmin_currencies.enable') }}
                                        </button>
                                    @endunless
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if(in_array($kind, ['create', 'edit'], true))
        <x-ui.drawer :title="$kind === 'create' ? __('sadmin_currencies.add') : __('sadmin_currencies.edit_title', ['code' => $code])" submit="save">
            <x-ui.field :label="__('sadmin_currencies.fields.code')" for="currency-code" name="code" required :help="__('sadmin_currencies.fields.code_help')">
                <input id="currency-code" type="text" dir="ltr" class="mono" wire:model.live.debounce.300ms="code" maxlength="3" list="iso-codes" autocomplete="off" @readonly($kind === 'edit') required>
                <datalist id="iso-codes">
                    @foreach(['IQD', 'USD', 'EUR', 'GBP', 'TRY', 'AED', 'SAR', 'JOD', 'KWD', 'QAR', 'BHD', 'OMR', 'EGP', 'IRR', 'SYP', 'LBP'] as $iso)<option value="{{ $iso }}">@endforeach
                </datalist>
            </x-ui.field>
            <x-ui.lang-tabs id="currency-name" :fields="[['name' => 'name', 'label' => __('sadmin_currencies.fields.name'), 'max' => 80, 'required' => true]]" :values="['name' => $name]" primary="en" />
            <div class="form-grid">
                <x-ui.field :label="__('sadmin_currencies.fields.symbol')" for="currency-symbol" name="symbol" required>
                    <input id="currency-symbol" type="text" dir="ltr" wire:model="symbol" maxlength="12" required>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_currencies.fields.decimals')" for="currency-decimals" name="decimals" required :help="$locked ? __('sadmin_currencies.fields.decimals_locked') : null">
                    <select id="currency-decimals" wire:model="decimals" @disabled($locked)>
                        @foreach([0, 1, 2, 3] as $places)<option value="{{ $places }}">{{ $places }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('sadmin_currencies.fields.order')" for="currency-order" name="sortOrder" required>
                    <input id="currency-order" type="number" min="0" max="999" wire:model="sortOrder" required>
                </x-ui.field>
            </div>
            <label class="choice choice--switch">
                <input type="checkbox" role="switch" wire:model="enabled">
                <span>{{ __('sadmin_currencies.fields.enabled') }}</span>
            </label>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @elseif($kind === 'default')
        <x-ui.modal :title="__('sadmin_currencies.default_title', ['code' => substr($panel, 8)])" :description="__('sadmin_currencies.default_body')" icon="star" submit="makeDefault">
            @error('code')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="makeDefault">{{ __('sadmin_currencies.make_default') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
