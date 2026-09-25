{{--
    The till. docs/18-SALES.md §§43–44.

    Every number on this screen came back from the server: the cart's totals
    are what SalePricing wrote onto the sale, and the view formats nothing.
    Inputs are what a person TYPES; the Action decides whether that is allowed.

    For a sale opened from a visit, the BOOKED · PERFORMED · CHARGED table is the
    point of the screen. Logical CSS properties only, so Arabic and Kurdish work
    through the same markup; the two panes stack on a tablet in portrait.
--}}
<div class="stack pos-till" x-data="posTill">
    <x-ui.page-header :title="__('ui.manager_nav.items.pos')">
        <x-slot:meta><span class="sr-only">{{ __('Till') }}</span></x-slot:meta>
        <x-slot:actions>
            @if($canSupervise || $canShift)
                <a class="button button--ghost" href="{{ route('center.shifts') }}" wire:navigate><x-ui.icon name="clock" size="16" />{{ __('manager_pos.shift.all_shifts') }}</a>
            @endif
            @if($canSettings)
                <a class="button button--ghost" href="{{ route('center.pos.settings') }}" wire:navigate><x-ui.icon name="settings" size="16" />{{ __('manager_pos.settings.title') }}</a>
            @endif
            @if($canCreate)
                <button class="button" type="button" wire:click="newSale" wire:loading.attr="data-loading" wire:target="newSale"><x-ui.icon name="plus" size="16" />{{ __('New sale') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
    @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

    @include('livewire.center.pointOfSale.shift-bar')

    <div class="till pos-till__grid">
        @include('livewire.center.pointOfSale.catalog')

        <section class="till__cart pos-cart" aria-label="{{ __('Sale') }}" wire:loading.class="is-refreshing" wire:target="addService,addProduct,addStage,addCustom,quantity,removeLine,addAdjustment,removeAdjustment,attachCustomer,createCustomer,overrideLinePrice,clearOverride">
            @if($cart === null)
                <x-ui.empty-state icon="cart" :title="__('manager_pos.cart.empty_title')">
                    @if($canCreate)<button class="button button--sm" type="button" wire:click="newSale">{{ __('New sale') }}</button>@endif
                </x-ui.empty-state>
            @else
                @include('livewire.center.pointOfSale.cart')
            @endif
        </section>
    </div>
</div>
