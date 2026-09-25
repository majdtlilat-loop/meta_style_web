{{--
    The till's benefits panel: sell a membership or package, redeem points,
    cover a service line with a package or a member's price.

    docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §27. Every button calls a benefit
    Action; this view computes nothing — option labels arrive ready.
--}}
<section class="pos-benefits" aria-labelledby="benefits-title-{{ $this->getId() }}" @unless($available) hidden @endunless>
    <header class="pos-benefits__head">
        <span class="pos-benefits__icon" aria-hidden="true"><x-ui.icon name="gift" size="16" /></span>
        <h3 id="benefits-title-{{ $this->getId() }}">{{ __('Benefits') }}</h3>
    </header>

    @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
    @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

    @unless($hasContent)
        <p class="muted pos-benefits__empty">{{ __('manager_pos.benefits.none') }}</p>
    @endunless

    @if($draft && $offerings !== [])
        <form wire:submit="addOffering" class="pos-benefits__row">
            <label class="sr-only" for="offering-{{ $this->getId() }}">{{ __('Membership or package') }}</label>
            <select id="offering-{{ $this->getId() }}" wire:model="offering" class="grow">
                <option value="">{{ __('Sell a membership or package') }}</option>
                @foreach($offerings as $option)
                    <option value="{{ $option['key'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            <button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="addOffering"><x-ui.icon name="plus" size="14" />{{ __('Add') }}</button>
        </form>
    @endif

    @if($loyalty !== null)
        <div class="pos-benefits__loyalty">
            <div class="cluster cluster--between">
                <span><x-ui.icon name="loyalty" size="14" /> {{ __('Points') }}: <strong class="tabular">{{ $loyalty['available_points'] }}</strong></span>
                @if($loyalty['tier'] !== null)<span class="badge">{{ $loyalty['tier']['name'] }}</span>@endif
            </div>

            @if($redeemed !== null)
                <div class="cluster cluster--between">
                    <span>{{ $redeemed['label'] }} <span dir="ltr" class="text-success">−{{ $redeemed['amount']['formatted'] }}</span></span>
                    @if($draft)
                        <button class="button button--ghost button--sm" type="button" wire:click="withdrawPoints" wire:loading.attr="data-loading" wire:target="withdrawPoints">{{ __('Give points back') }}</button>
                    @endif
                </div>
            @elseif($canRedeem)
                <form wire:submit="redeem" class="pos-benefits__row">
                    <label class="sr-only" for="points-{{ $this->getId() }}">{{ __('Points to redeem') }}</label>
                    <input id="points-{{ $this->getId() }}" type="number" min="1" inputmode="numeric" wire:model="points" placeholder="{{ __('Points to redeem') }}" class="grow">
                    <button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="redeem">{{ __('Redeem') }}</button>
                </form>
            @endif
        </div>
    @endif

    @if($lines !== [] && $coverOptions !== [])
        <ul class="pos-benefits__lines">
            @foreach($lines as $line)
                <li wire:key="benefit-line-{{ $line['uuid'] }}">
                    <span class="cell-title">{{ $line['name'] }} <span class="muted tabular">× {{ $line['quantity'] }}</span></span>
                    @if($line['benefit'] !== null)
                        <div class="cluster cluster--between">
                            <span class="cell-sub">{{ $line['benefit']['label'] }}</span>
                            @if($draft)
                                <button class="button button--ghost button--sm" type="button" wire:click="uncover('{{ $line['uuid'] }}', '{{ $line['benefit']['source'] }}')" wire:loading.attr="data-loading" wire:target="uncover">{{ __('Withdraw') }}</button>
                            @endif
                        </div>
                    @elseif($draft)
                        <form class="stack stack--sm" wire:submit="cover('{{ $line['uuid'] }}')">
                            <div class="pos-benefits__row">
                                <label class="sr-only" for="cover-{{ $line['uuid'] }}">{{ __('Benefit for this line') }}</label>
                                <select id="cover-{{ $line['uuid'] }}" wire:model="coverWith.{{ $line['uuid'] }}" class="grow">
                                    <option value="">{{ __('Use…') }}</option>
                                    @foreach($coverOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="cover">{{ __('Apply') }}</button>
                            </div>
                            @unless($line['from_visit'])
                                <label class="choice">
                                    <input type="checkbox" wire:model="performed.{{ $line['uuid'] }}">
                                    <span>{{ __('This service was performed (needed to use a package session)') }}</span>
                                </label>
                            @endunless
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
