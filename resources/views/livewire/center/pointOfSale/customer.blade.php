{{-- Who the sale is for. Contact details arrive already masked by the presenter. --}}
<div class="till__customer pos-customer">
    <span class="avatar" aria-hidden="true"><x-ui.icon name="user" size="16" /></span>
    <div class="grow">
        <span class="cell-sub">{{ __('Customer') }}</span>
        <strong>{{ $cart['customer']['name'] ?? __('Walk-up customer') }}</strong>
    </div>
    @if($draft && $canCreate && $cart['customer'] !== null)
        <button class="icon-button icon-button--sm" type="button" wire:click="attachCustomer('')" aria-label="{{ __('Remove customer') }}" title="{{ __('Remove customer') }}"><x-ui.icon name="close" size="16" /></button>
    @endif
</div>

@if($draft && $canCreate)
    <div class="till__customer-search pos-customer__search">
        @if($canFindCustomer)
            <div class="search-input">
                <x-ui.icon name="search" />
                <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="{{ __('manager_pos.customer.search') }}" aria-label="{{ __('manager_pos.customer.search') }}" autocomplete="off">
            </div>
            @if($customers !== [])
                <ul class="pos-customer__results" role="list">
                    @foreach($customers as $match)
                        <li wire:key="customer-{{ $match['uuid'] }}">
                            <button type="button" class="pos-customer__match" wire:click="attachCustomer('{{ $match['uuid'] }}')">
                                <span class="cell-title">{{ $match['name'] }}</span>
                                @if($match['contact'] !== null)<span class="cell-sub" dir="ltr">{{ $match['contact'] }}</span>@endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif($customerSearched)
                <p class="field-help">{{ __('manager_pos.customer.no_match') }}</p>
            @endif
        @endif

        @if($canAddCustomer)
            @if($addingCustomer)
                <form class="pos-customer__new stack stack--sm" wire:submit="createCustomer">
                    <x-ui.field :label="__('manager_pos.customer.name')" for="new-customer-name" name="newCustomerName" required>
                        <input id="new-customer-name" type="text" wire:model="newCustomerName" maxlength="120" required autocomplete="off">
                    </x-ui.field>
                    <x-ui.phone number="newCustomerPhone" country="newCustomerCountry" :help="__('manager_pos.customer.phone_help')" />
                    <div class="cluster">
                        <button class="button button--sm" type="submit" wire:loading.attr="data-loading" wire:target="createCustomer"><x-ui.icon name="user-plus" size="14" />{{ __('manager_pos.customer.add_and_attach') }}</button>
                        <button class="button button--secondary button--sm" type="button" wire:click="cancelNewCustomer">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @else
                <div><button class="button button--ghost button--sm" type="button" wire:click="startNewCustomer"><x-ui.icon name="user-plus" size="14" />{{ __('manager_pos.customer.new') }}</button></div>
            @endif
        @endif
    </div>
@endif
