{{-- The open sale. Figures are the server's; nothing here adds anything up. --}}
<header class="till__cart-head">
    <div>
        <h2>{{ __('Sale') }}</h2>
        <span class="cell-sub">{{ $cart['source_label'] }}</span>
    </div>
    <div class="cluster cluster--tight">
        <x-ui.status :value="$cart['status']" :label="$cart['status_label']" />
        <button class="icon-button icon-button--sm" type="button" wire:click="closeSale" aria-label="{{ __('manager_pos.cart.close') }}" title="{{ __('manager_pos.cart.close') }}"><x-ui.icon name="close" /></button>
    </div>
</header>

{{-- Booked · Performed · Charged --}}
@if($checkout !== [])
    <div class="table-shell table-shell--stack pos-checkout">
        <table class="checkout-review">
            <caption>{{ __('This visit') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Service') }}</th>
                    <th scope="col">{{ __('Booked') }}</th>
                    <th scope="col">{{ __('Performed') }}</th>
                    <th scope="col">{{ __('Charged') }}</th>
                    <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($checkout as $row)
                    <tr wire:key="stage-{{ $row['stage'] }}">
                        <td data-label="{{ __('Service') }}" data-primary><span class="cell-title">{{ $row['service'] }}</span></td>
                        <td data-label="{{ __('Booked') }}"><span dir="ltr">{{ $row['booked']['formatted'] ?? __('Walk-in') }}</span></td>
                        <td data-label="{{ __('Performed') }}"><x-ui.status :value="$row['performed']" :label="$row['performed_label']" :dot="false" /></td>
                        <td data-label="{{ __('Charged') }}"><span dir="ltr">{{ $row['charged']['formatted'] ?? __('Not charged') }}</span></td>
                        <td class="actions">
                            @if($row['chargeable'] && $canCreate)
                                <button class="button button--secondary button--sm" type="button" wire:click="addStage('{{ $row['stage'] }}')">{{ __('Charge') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@include('livewire.center.pointOfSale.customer')

<ul class="cart-lines pos-lines" aria-label="{{ __('manager_pos.cart.lines') }}">
    @forelse($cart['lines'] as $line)
        <li class="cart-line" wire:key="line-{{ $line['uuid'] }}">
            <div class="cart-line__main">
                <strong>{{ $line['name'] }}@if($line['variation'] !== null) <span class="muted">— {{ $line['variation'] }}</span>@endif</strong>
                @foreach($line['addons'] as $addon)
                    <span class="cell-sub">+ {{ $addon['name'] }} (<span dir="ltr">{{ $addon['unit_price']['formatted'] }}</span>)</span>
                @endforeach
                @if($line['overridden'])
                    {{-- The original stays visible: an override is never silent. --}}
                    <span class="cell-sub text-warning">{{ __('Was :price — :reason', ['price' => $line['original_unit_price']['formatted'], 'reason' => $line['override_reason']]) }}</span>
                @endif
                <span class="cell-sub"><span class="pos-kind">{{ $line['kind_label'] }}</span> · <span dir="ltr">{{ $line['unit_price']['formatted'] }}</span>@if($line['employee'] !== null) · {{ __('manager_pos.cart.by', ['name' => $line['employee']['name']]) }}@endif</span>
            </div>
            <div class="cart-line__qty">
                @if($draft && $canCreate && $line['steppable'])
                    <button class="icon-button icon-button--sm" type="button" wire:click="quantity('{{ $line['uuid'] }}', {{ $line['previous_quantity'] }})" @disabled(! $line['can_decrease']) aria-label="{{ __('manager_pos.cart.decrease', ['name' => $line['name']]) }}">−</button>
                    <span class="tabular pos-qty" aria-live="polite">{{ $line['quantity'] }}</span>
                    <button class="icon-button icon-button--sm" type="button" wire:click="quantity('{{ $line['uuid'] }}', {{ $line['next_quantity'] }})" @disabled(! $line['can_increase']) aria-label="{{ __('manager_pos.cart.increase', ['name' => $line['name']]) }}">+</button>
                @else
                    <span class="tabular">× {{ $line['quantity'] }}</span>
                @endif
            </div>
            <strong class="cart-line__amount tabular" dir="ltr">{{ $line['line_subtotal']['formatted'] }}</strong>
            @if($draft)
                <div class="cart-line__tools">
                    {{-- A performed service stays on the bill; its price can change, with a reason.
                         A line carrying a benefit changes through the benefits panel. --}}
                    @if($canAdjust && ! $line['has_benefit'])
                        @if($line['overridden'])
                            <button class="text-button" type="button" wire:click="clearOverride('{{ $line['uuid'] }}')">{{ __('Restore price') }}</button>
                        @else
                            <button class="text-button" type="button" wire:click="startOverride('{{ $line['uuid'] }}')">{{ __('Change price') }}</button>
                        @endif
                    @endif
                    @if($canCreate && ! $line['from_visit'] && ! $line['has_benefit'])
                        <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeLine('{{ $line['uuid'] }}')" aria-label="{{ __('manager_pos.cart.remove', ['name' => $line['name']]) }}" title="{{ __('Remove') }}"><x-ui.icon name="trash" /></button>
                    @endif
                </div>
            @endif
        </li>
    @empty
        <li class="cart-line cart-line--empty muted">{{ __('No items yet.') }}</li>
    @endforelse
</ul>

@if($overrideLine !== '' && $canAdjust && $draft)
    <form class="pay-methods stack stack--sm" wire:submit="overrideLinePrice">
        <strong>{{ __('Change price') }}</strong>
        <div class="form-grid">
            <x-ui.field :label="__('New unit price')" for="override-price" required>
                <input id="override-price" type="text" dir="ltr" inputmode="decimal" wire:model="overridePrice" required autocomplete="off">
            </x-ui.field>
            <x-ui.field :label="__('Reason')" for="override-reason" required>
                <input id="override-reason" type="text" wire:model="overrideReason" maxlength="190" required>
            </x-ui.field>
        </div>
        <div class="cluster">
            <button class="button button--sm" type="submit" wire:loading.attr="data-loading" wire:target="overrideLinePrice">{{ __('Apply') }}</button>
            <button class="button button--secondary button--sm" type="button" wire:click="$set('overrideLine', '')">{{ __('Cancel') }}</button>
        </div>
    </form>
@endif

<dl class="summary-list till__totals pos-totals">
    <div><dt>{{ __('Subtotal') }}</dt><dd dir="ltr">{{ $cart['subtotal']['formatted'] }}</dd></div>
    @foreach($cart['adjustments'] as $adjustment)
        <div wire:key="adjustment-{{ $adjustment['uuid'] }}">
            <dt>
                {{ $adjustment['label'] }}
                @if($adjustment['reason'])<small class="cell-sub">{{ $adjustment['reason'] }}</small>@endif
            </dt>
            <dd>
                <span dir="ltr" @class(['text-success' => $adjustment['is_discount']])>{{ $adjustment['sign'] }}{{ $adjustment['amount']['formatted'] }}</span>
                @if($draft && $canAdjust && ! $adjustment['benefit'])
                    <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeAdjustment('{{ $adjustment['uuid'] }}')" aria-label="{{ __('manager_pos.cart.remove', ['name' => $adjustment['label']]) }}"><x-ui.icon name="close" /></button>
                @endif
            </dd>
        </div>
    @endforeach
    <div class="settlement__grand"><dt>{{ __('Total') }}</dt><dd dir="ltr">{{ $cart['grand_total']['formatted'] }}</dd></div>
</dl>

@if($draft && $canAdjust)
    <details class="disclosure">
        <summary>{{ __('manager_pos.cart.adjust') }}</summary>
        <form class="disclosure__body stack stack--sm" wire:submit="addAdjustment">
            <div class="segmented" role="radiogroup" aria-label="{{ __('Type') }}">
                @foreach(['discount_percent' => __('Discount %'), 'discount_fixed' => __('Discount amount'), 'surcharge_fixed' => __('Surcharge amount')] as $type => $typeLabel)
                    <label @class(['is-active' => $adjustmentType === $type])><input class="sr-only" type="radio" value="{{ $type }}" wire:model.live="adjustmentType">{{ $typeLabel }}</label>
                @endforeach
            </div>
            <div class="form-grid">
                <x-ui.field :label="__('Value')" for="adjustment-value" required>
                    <input id="adjustment-value" type="text" dir="ltr" inputmode="decimal" wire:model="adjustmentValue" required autocomplete="off">
                </x-ui.field>
                <x-ui.field :label="__('Reason')" for="adjustment-reason" required>
                    <input id="adjustment-reason" type="text" wire:model="adjustmentReason" maxlength="190" required>
                </x-ui.field>
            </div>
            <div><button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="addAdjustment">{{ __('Apply') }}</button></div>
        </form>
    </details>
@endif

{{-- Loyalty, memberships and packages: their own component, so the till
     imports none of those modules (docs/21 §27). --}}
@if($draft)
    <livewire:center.till-benefits :sale="$cart['uuid']" :key="'benefits-'.$cart['uuid']" />
@endif

@if($draft)
    @if($visitInProgress)
        <div class="notice" data-tone="info" role="note"><x-ui.icon name="info" /><p>{{ __('This visit is still in progress. You can prepare the bill now; it can be finalized once the visit is completed.') }}</p></div>
    @elseif($visitAbandoned)
        <div class="notice" data-tone="warning" role="note"><x-ui.icon name="alert-triangle" /><p>{{ __('This visit was abandoned. Its sale cannot be finalized; discard it.') }}</p></div>
    @endif

    <div class="till__actions pos-actions">
        @if($canCreate)
            <button class="button button--ghost button--danger-text" type="button" wire:click="discard" wire:confirm="{{ __('Discard this sale?') }}" data-confirm-title="{{ __('Discard') }}" data-confirm-tone="danger">{{ __('Discard') }}</button>
        @endif
        @if($canFinalize)
            <button class="button button--lg" type="button" wire:click="finalize" wire:loading.attr="data-loading" wire:target="finalize" @disabled($cart['lines'] === [] || $visitInProgress || $visitAbandoned)><x-ui.icon name="receipt" size="16" />{{ __('Finalize and issue invoice') }}</button>
        @endif
    </div>
@elseif($cart['invoice'] !== null)
    @include('livewire.center.pointOfSale.issued')
@endif
