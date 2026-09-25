{{-- What can go on the open sale, and the drafts waiting at this branch. --}}
<section class="till__catalog stack pos-catalog" aria-label="{{ __('manager_pos.catalog.label') }}">
    @if($draft && $canCreate)
        <div class="pos-catalog__search">
            <div class="search-input">
                <x-ui.icon name="search" />
                <input type="search" data-pos-search wire:model.live.debounce.300ms="search" wire:keydown.enter.prevent="scan" placeholder="{{ __('Search services, products, or scan a barcode') }}" aria-label="{{ __('Search') }}" aria-describedby="pos-search-hint" title="{{ __('manager_pos.catalog.scan_hint') }}" autocomplete="off" enterkeyhint="search">
            </div>
            <p class="sr-only" id="pos-search-hint">{{ __('manager_pos.catalog.scan_hint') }}</p>
        </div>

        @if($categories !== [])
            <div class="segmented segmented--scroll pos-catalog__categories" role="group" aria-label="{{ __('manager_pos.catalog.categories') }}">
                <button type="button" wire:click="$set('category', '')" aria-pressed="{{ $category === '' ? 'true' : 'false' }}">{{ __('manager_pos.catalog.all') }}</button>
                @foreach($categories as $option)
                    <button type="button" wire:click="$set('category', '{{ $option['uuid'] }}')" aria-pressed="{{ $category === $option['uuid'] ? 'true' : 'false' }}" wire:key="category-{{ $option['uuid'] }}">{{ $option['name'] }}</button>
                @endforeach
            </div>
        @endif

        <div wire:loading.class="is-refreshing" wire:target="search,category">
            <h2 class="till__heading">{{ __('Services') }}</h2>
            @if($services === [])
                <p class="muted pos-catalog__empty">{{ $search !== '' ? __('manager_pos.catalog.no_match') : __('No services yet.') }}</p>
            @else
                <div class="tile-grid pos-tiles">
                    @foreach($services as $service)
                        <button class="tile pos-tile" type="button" wire:key="service-{{ $service['uuid'] }}" wire:click="pick('{{ $service['uuid'] }}')" @if($pick !== null && $pick['uuid'] === $service['uuid']) aria-pressed="true" @endif>
                            <span class="tile__name">{{ $service['name'] }}</span>
                            <span class="tile__price" dir="ltr">{{ $service['price']['formatted'] }}</span>
                            @if($service['configurable'])<span class="pos-tile__hint">{{ __('manager_pos.catalog.has_options') }}</span>@endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if($pick !== null)
            <form class="pick-panel stack stack--sm pos-pick" wire:submit="addService" aria-labelledby="pick-title">
                <div class="cluster cluster--between">
                    <strong id="pick-title">{{ $pick['name'] }}</strong>
                    <button class="icon-button icon-button--sm" type="button" wire:click="cancelPick" aria-label="{{ __('ui.actions.cancel') }}" title="{{ __('ui.actions.cancel') }}"><x-ui.icon name="close" /></button>
                </div>
                @if($pick['variations'] !== [])
                    <x-ui.field :label="__('Option')" for="pick-variation" name="pickVariation">
                        <select id="pick-variation" wire:model="pickVariation">
                            <option value="">{{ __('Standard') }} · {{ $pick['price']['formatted'] }}</option>
                            @foreach($pick['variations'] as $variation)
                                <option value="{{ $variation['uuid'] }}">{{ $variation['name'] }} · {{ $variation['price']['formatted'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                @if($pick['addons'] !== [])
                    <fieldset class="field">
                        <legend>{{ __('Extras') }}</legend>
                        <div class="chip-select">
                            @foreach($pick['addons'] as $addon)
                                <label class="chip-toggle" wire:key="addon-{{ $addon['uuid'] }}"><input type="checkbox" wire:model="pickAddons" value="{{ $addon['uuid'] }}"><span>{{ $addon['name'] }} · <span dir="ltr">+{{ $addon['price']['formatted'] }}</span></span></label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                @if($pick['employees'] !== [])
                    <x-ui.field :label="__('manager_pos.catalog.performed_by')" for="pick-employee" name="pickEmployee">
                        <select id="pick-employee" wire:model="pickEmployee">
                            <option value="">{{ __('manager_pos.catalog.nobody') }}</option>
                            @foreach($pick['employees'] as $employee)
                                <option value="{{ $employee['uuid'] }}">{{ $employee['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <div class="cluster cluster--between">
                    <div class="field field--inline">
                        <label for="pick-quantity">{{ __('Quantity') }}</label>
                        <input id="pick-quantity" class="input--qty" type="number" min="1" max="999" inputmode="numeric" wire:model="pickQuantity">
                    </div>
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="addService"><x-ui.icon name="plus" size="16" />{{ __('Add to sale') }}</button>
                </div>
            </form>
        @endif

        <div wire:loading.class="is-refreshing" wire:target="search">
            <h2 class="till__heading">{{ __('Products') }}</h2>
            @if($products === [])
                <p class="muted pos-catalog__empty">{{ $search !== '' ? __('manager_pos.catalog.no_match') : __('No products.') }}</p>
            @else
                <div class="tile-grid pos-tiles">
                    @foreach($products as $product)
                        <button class="tile pos-tile" type="button" wire:key="product-{{ $product['uuid'] }}" wire:click="addProduct('{{ $product['uuid'] }}')" wire:loading.attr="disabled" wire:target="addProduct">
                            <span class="tile__name">{{ $product['name'] }}</span>
                            <span class="tile__price" dir="ltr">{{ $product['price']['formatted'] }}</span>
                            @if($product['code'] !== null)<span class="pos-tile__hint" dir="ltr">{{ $product['code'] }}</span>@endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if($canAdjust)
            <details class="disclosure">
                <summary>{{ __('Custom charge') }}</summary>
                <form class="disclosure__body stack stack--sm" wire:submit="addCustom">
                    <x-ui.field :label="__('Description')" for="custom-name" required>
                        <input id="custom-name" type="text" wire:model="customName" maxlength="120" required>
                    </x-ui.field>
                    <div class="form-grid">
                        <x-ui.field :label="__('Price')" for="custom-price" required>
                            <input id="custom-price" type="text" dir="ltr" inputmode="decimal" wire:model="customPrice" required autocomplete="off">
                        </x-ui.field>
                        <x-ui.field :label="__('Reason')" for="custom-reason" required>
                            <input id="custom-reason" type="text" wire:model="customReason" maxlength="190" required>
                        </x-ui.field>
                    </div>
                    <div><button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="addCustom"><x-ui.icon name="plus" size="14" />{{ __('Add') }}</button></div>
                </form>
            </details>
        @endif
    @elseif($cart !== null && $cart['status'] !== 'draft')
        <x-ui.empty-state compact icon="receipt" :title="__('manager_pos.catalog.issued_title')">
            @if($canCreate)<button class="button button--sm" type="button" wire:click="newSale">{{ __('New sale') }}</button>@endif
        </x-ui.empty-state>
    @endif

    <div class="pos-drafts">
        <h2 class="till__heading">{{ __('Open drafts') }}</h2>
        @if(empty($drafts))
            <p class="muted">{{ __('No open drafts.') }}</p>
        @else
            <ul class="row-list row-list--boxed">
                @foreach($drafts as $option)
                    <li class="row-list__item" wire:key="draft-{{ $option['uuid'] }}" @if($cart !== null && $cart['uuid'] === $option['uuid']) aria-current="true" @endif>
                        <button class="row-list__button" type="button" wire:click="openSale('{{ $option['uuid'] }}')">
                            <span class="row-list__body"><span class="cell-title">{{ $option['customer']['name'] ?? __('Walk-up customer') }}</span><span class="cell-sub">{{ $option['created_by'] }}</span></span>
                            <span class="tabular" dir="ltr">{{ $option['grand_total']['formatted'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
