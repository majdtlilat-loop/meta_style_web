{{--
    The till.

    docs/18-SALES.md §§43–44.

    Every number on this screen came back from the server. The cart's totals are
    what `SalePricing` wrote onto the sale; nothing here adds, multiplies or
    rounds an amount. Inputs are what a person TYPES — "25000", "12.5" — and the
    Action decides whether that is allowed.

    For a sale opened from a visit, the BOOKED · PERFORMED · CHARGED table is the
    point of the screen: three facts that look alike, side by side, so nobody
    charges a skipped service or forgets a performed one.

    Logical CSS properties only, so Arabic and Kurdish work through the same
    markup.
--}}
<div>
    <x-center-nav />

    <h1>{{ __('Till') }}</h1>

    @if ($error !== '')
        <p role="alert" class="error">{{ $error }}</p>
    @endif

    @if ($saved !== '')
        <p role="status">{{ $saved }}</p>
    @endif

    <section class="till-bar">
        <label>
            {{ __('Branch') }}
            <select wire:model.live="branch">
                @foreach ($branches as $option)
                    <option value="{{ $option->uuid }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        @if ($canShift)
            @if ($shift === null)
                <span>{{ __('No open shift.') }}</span>
                <input type="text" wire:model="shiftNote" placeholder="{{ __('Opening note (optional)') }}" maxlength="190">
                <button type="button" wire:click="openShift">{{ __('Open shift') }}</button>
            @else
                <span>
                    {{ __('Shift open since :time', ['time' => $shiftOpenedAt]) }}
                    @if ($shiftSummary !== null)
                        · {{ trans_choice(':count sale|:count sales', $shiftSummary['sales'], ['count' => $shiftSummary['sales']]) }}
                    @endif
                </span>
                <input type="text" wire:model="shiftNote" placeholder="{{ __('Closing note (optional)') }}" maxlength="190">
                <button type="button" wire:click="closeShift('{{ $shift['uuid'] }}')"
                        wire:confirm="{{ __('Close your shift?') }}">{{ __('Close shift') }}</button>
            @endif
        @endif

        @if ($canCreate)
            <button type="button" wire:click="newSale" wire:loading.attr="disabled">{{ __('New sale') }}</button>
        @endif
    </section>

    <div class="till">
        {{-- ---------------------------------------------------- catalog --}}
        <section class="catalog">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('Search services, products, or scan a barcode') }}">

            @if ($cart !== null && $cart['status'] === 'draft' && $canCreate)
                <h2>{{ __('Services') }}</h2>
                <ul>
                    @foreach ($services as $service)
                        <li>
                            <button type="button" wire:click="pick('{{ $service->uuid }}')">
                                {{ $service->name }} · {{ $service->price()->formatted() }}
                            </button>
                        </li>
                    @endforeach
                </ul>

                @if ($pick !== null)
                    <form wire:submit="addService" class="pick">
                        <strong>{{ $pick->name }}</strong>

                        @if ($pick->variations->isNotEmpty())
                            <label>
                                {{ __('Option') }}
                                <select wire:model="pickVariation">
                                    <option value="">{{ __('Standard') }}</option>
                                    @foreach ($pick->variations as $variation)
                                        <option value="{{ $variation->uuid }}">
                                            {{ $variation->name }} · {{ $variation->effectivePrice($pick)->formatted() }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        @endif

                        @foreach ($pick->addons as $addon)
                            <label>
                                <input type="checkbox" wire:model="pickAddons" value="{{ $addon->uuid }}">
                                {{ $addon->name }} · +{{ $addon->price()->formatted() }}
                            </label>
                        @endforeach

                        <label>
                            {{ __('Quantity') }}
                            <input type="number" min="1" max="999" wire:model="pickQuantity">
                        </label>

                        <button type="submit">{{ __('Add to sale') }}</button>
                    </form>
                @endif

                <h2>{{ __('Products') }}</h2>
                <ul>
                    @forelse ($products as $product)
                        <li>
                            <button type="button" wire:click="addProduct('{{ $product->uuid }}')">
                                {{ $product->name }} · {{ $product->price()->formatted() }}
                            </button>
                        </li>
                    @empty
                        <li>{{ __('No products.') }}</li>
                    @endforelse
                </ul>

                @if ($canAdjust)
                    <details>
                        <summary>{{ __('Custom charge') }}</summary>
                        <form wire:submit="addCustom">
                            <input type="text" wire:model="customName" placeholder="{{ __('Description') }}" maxlength="120">
                            <input type="text" inputmode="decimal" wire:model="customPrice" placeholder="{{ __('Price') }}">
                            <input type="text" wire:model="customReason" placeholder="{{ __('Reason') }}" maxlength="190">
                            <button type="submit">{{ __('Add') }}</button>
                        </form>
                    </details>
                @endif
            @endif

            <h2>{{ __('Open drafts') }}</h2>
            <ul>
                @forelse ($drafts as $draft)
                    <li>
                        <button type="button" wire:click="openSale('{{ $draft['uuid'] }}')">
                            {{ $draft['customer']['name'] ?? __('Walk-up customer') }} · {{ $draft['grand_total']['formatted'] }}
                        </button>
                    </li>
                @empty
                    <li>{{ __('No open drafts.') }}</li>
                @endforelse
            </ul>
        </section>

        {{-- ------------------------------------------------------- cart --}}
        <section class="cart">
            @if ($cart === null)
                <p>{{ __('Start a new sale, open a draft, or check a visit out from the board.') }}</p>
            @else
                <h2>
                    {{ __('Sale') }}
                    <small>({{ __($cart['status']) }})</small>
                </h2>

                {{-- Booked · Performed · Charged --}}
                @if ($checkout !== [])
                    <table class="checkout-review">
                        <caption>{{ __('This visit') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Service') }}</th>
                                <th scope="col">{{ __('Booked') }}</th>
                                <th scope="col">{{ __('Performed') }}</th>
                                <th scope="col">{{ __('Charged') }}</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($checkout as $row)
                                <tr>
                                    <td>{{ $row['service'] }}</td>
                                    <td>{{ $row['booked']['formatted'] ?? __('Walk-in') }}</td>
                                    <td>{{ __($row['performed']) }}</td>
                                    <td>{{ $row['charged']['formatted'] ?? __('Not charged') }}</td>
                                    <td>
                                        @if ($row['chargeable'])
                                            <button type="button" wire:click="addStage('{{ $row['stage'] }}')">{{ __('Charge') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <div class="customer">
                    <strong>{{ __('Customer') }}:</strong>
                    {{ $cart['customer']['name'] ?? __('None') }}

                    @if ($cart['status'] === 'draft' && $canCreate)
                        <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="{{ __('Find a customer by name') }}">
                        @foreach ($customers as $customer)
                            <button type="button" wire:click="attachCustomer('{{ $customer['uuid'] }}')">{{ $customer['name'] }}</button>
                        @endforeach
                        @if ($cart['customer'] !== null)
                            <button type="button" wire:click="attachCustomer('')">{{ __('Remove customer') }}</button>
                        @endif
                    @endif
                </div>

                <table class="lines">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Item') }}</th>
                            <th scope="col">{{ __('Qty') }}</th>
                            <th scope="col">{{ __('Price') }}</th>
                            <th scope="col">{{ __('Amount') }}</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cart['lines'] as $line)
                            <tr wire:key="line-{{ $line['uuid'] }}">
                                <td>
                                    {{ $line['name'] }}
                                    @if ($line['variation'] !== null) — {{ $line['variation'] }} @endif
                                    @foreach ($line['addons'] as $addon)
                                        <div class="addon">+ {{ $addon['name'] }} ({{ $addon['unit_price']['formatted'] }})</div>
                                    @endforeach
                                    @if ($line['overridden'])
                                        {{-- The original stays visible: an override is never silent. --}}
                                        <div class="override">
                                            {{ __('Was :price — :reason', ['price' => $line['original_unit_price']['formatted'], 'reason' => $line['override_reason']]) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if ($cart['status'] === 'draft' && $canCreate && ! $line['from_visit'])
                                        <button type="button" wire:click="quantity('{{ $line['uuid'] }}', {{ $line['quantity'] - 1 }})" @disabled($line['quantity'] <= 1)>−</button>
                                        {{ $line['quantity'] }}
                                        <button type="button" wire:click="quantity('{{ $line['uuid'] }}', {{ $line['quantity'] + 1 }})">+</button>
                                    @else
                                        {{ $line['quantity'] }}
                                    @endif
                                </td>
                                <td>{{ $line['unit_price']['formatted'] }}</td>
                                <td>{{ $line['line_subtotal']['formatted'] }}</td>
                                <td>
                                    @if ($cart['status'] === 'draft')
                                        {{-- A performed service stays on the bill; its price can change, with a reason. --}}
                                        @if ($canCreate && ! $line['from_visit'])
                                            <button type="button" wire:click="removeLine('{{ $line['uuid'] }}')">{{ __('Remove') }}</button>
                                        @endif
                                        @if ($canAdjust)
                                            @if ($line['overridden'])
                                                <button type="button" wire:click="clearOverride('{{ $line['uuid'] }}')">{{ __('Restore price') }}</button>
                                            @else
                                                <button type="button" wire:click="$set('overrideLine', '{{ $line['uuid'] }}')">{{ __('Change price') }}</button>
                                            @endif
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">{{ __('No items yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($overrideLine !== '' && $canAdjust)
                    <form wire:submit="overrideLinePrice" class="override-form">
                        <input type="text" inputmode="decimal" wire:model="overridePrice" placeholder="{{ __('New unit price') }}">
                        <input type="text" wire:model="overrideReason" placeholder="{{ __('Reason') }}" maxlength="190">
                        <button type="submit">{{ __('Apply') }}</button>
                        <button type="button" wire:click="$set('overrideLine', '')">{{ __('Cancel') }}</button>
                    </form>
                @endif

                <dl class="totals">
                    <div><dt>{{ __('Subtotal') }}</dt><dd>{{ $cart['subtotal']['formatted'] }}</dd></div>

                    @foreach ($cart['adjustments'] as $adjustment)
                        <div>
                            <dt>
                                {{ str_starts_with($adjustment['type'], 'discount') ? __('Discount') : __('Surcharge') }}
                                @if ($adjustment['percent'] !== null)
                                    ({{ $adjustment['percent'] }}%)
                                @endif
                                <small>{{ $adjustment['reason'] }}</small>
                            </dt>
                            <dd>
                                {{ $adjustment['amount']['formatted'] }}
                                @if ($cart['status'] === 'draft' && $canAdjust)
                                    <button type="button" wire:click="removeAdjustment('{{ $adjustment['uuid'] }}')">{{ __('Remove') }}</button>
                                @endif
                            </dd>
                        </div>
                    @endforeach

                    <div class="grand"><dt>{{ __('Total') }}</dt><dd>{{ $cart['grand_total']['formatted'] }}</dd></div>
                </dl>

                @if ($cart['status'] === 'draft' && $canAdjust)
                    <form wire:submit="addAdjustment" class="adjustment">
                        <select wire:model="adjustmentType">
                            <option value="discount_percent">{{ __('Discount %') }}</option>
                            <option value="discount_fixed">{{ __('Discount amount') }}</option>
                            <option value="surcharge_fixed">{{ __('Surcharge amount') }}</option>
                        </select>
                        <input type="text" inputmode="decimal" wire:model="adjustmentValue" placeholder="{{ __('Value') }}">
                        <input type="text" wire:model="adjustmentReason" placeholder="{{ __('Reason') }}" maxlength="190">
                        <button type="submit">{{ __('Apply') }}</button>
                    </form>
                @endif

                @if ($cart['status'] === 'draft')
                    @if ($visitInProgress)
                        <p role="note" class="notice">{{ __('This visit is still in progress. You can prepare the bill now; it can be finalized once the visit is completed.') }}</p>
                    @elseif ($visitAbandoned)
                        <p role="note" class="notice">{{ __('This visit was abandoned. Its sale cannot be finalized; discard it.') }}</p>
                    @endif

                    <div class="actions">
                        @if ($canFinalize)
                            <button type="button" wire:click="finalize" wire:loading.attr="disabled"
                                    @disabled($cart['lines'] === [] || $visitInProgress || $visitAbandoned)>{{ __('Finalize and issue invoice') }}</button>
                        @endif
                        @if ($canCreate)
                            <button type="button" wire:click="discard" wire:confirm="{{ __('Discard this sale?') }}">{{ __('Discard') }}</button>
                        @endif
                    </div>
                @elseif ($cart['invoice'] !== null)
                    <div class="invoice-issued">
                        <p>{{ __('Invoice :number', ['number' => $cart['invoice']['number']]) }}</p>

                        {{-- Shown once: only a digest of the link is kept. A new link is issued from Sales. --}}
                        @if ($customerLink !== '')
                            <p>
                                {{ __('Customer link') }}:
                                <a href="{{ $customerLink }}" target="_blank" rel="noopener noreferrer">{{ __('Open') }}</a>
                            </p>
                        @endif

                        @if ($canPrint)
                            <a href="{{ route('center.sales.invoice.print', ['uuid' => $cart['invoice']['uuid'], 'format' => '80mm']) }}" target="_blank">{{ __('Print receipt (80mm)') }}</a>
                            <a href="{{ route('center.sales.invoice.print', ['uuid' => $cart['invoice']['uuid'], 'format' => 'a4']) }}" target="_blank">{{ __('Print A4') }}</a>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    </div>
</div>
