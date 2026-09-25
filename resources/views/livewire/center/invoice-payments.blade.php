{{--
    An invoice's money: total · paid · pending · remaining, and taking it.

    docs/19-PAYMENTS.md §59. Every figure comes from the server's settlement;
    labels, tones and local times arrive ready — this view computes nothing.
--}}
<section class="invoice-payments drawer-section pos-money" aria-labelledby="payments-title-{{ $this->getId() }}">
    <div class="cluster cluster--between">
        <h3 id="payments-title-{{ $this->getId() }}">{{ __('Payment') }}</h3>
        @if($summary !== null)<x-ui.status :tone="$summary['state_tone']" :label="$summary['state_label']" />@endif
    </div>

    @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
    @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

    @if($summary !== null)
        <dl class="summary-list settlement">
            <div><dt>{{ __('Total') }}</dt><dd dir="ltr">{{ $summary['invoice_total']['formatted'] }}</dd></div>
            <div><dt>{{ __('Paid') }}</dt><dd dir="ltr">{{ $summary['succeeded']['formatted'] }}</dd></div>
            @if($summary['has_pending'])
                <div><dt>{{ __('Awaiting online payment') }}</dt><dd dir="ltr" class="text-warning">{{ $summary['pending']['formatted'] }}</dd></div>
            @endif
            <div class="settlement__grand"><dt>{{ __('Left to collect') }}</dt><dd dir="ltr">{{ $summary['available_collectible']['formatted'] }}</dd></div>
            @if($summary['has_refunds'])
                <div><dt>{{ __('Refunded') }}</dt><dd dir="ltr">{{ $summary['refunded']['formatted'] }}</dd></div>
                <div><dt>{{ __('Kept after refunds') }}</dt><dd dir="ltr">{{ $summary['net_collected']['formatted'] }}</dd></div>
            @endif
        </dl>

        @if($summary['voided'])
            <div class="notice" data-tone="warning" role="note"><x-ui.icon name="alert-triangle" /><p>{{ __('This sale was voided. No payment can be taken.') }}</p></div>
        @elseif($summary['collectible'] && $canTakeMoney)
            <div class="pay-methods pos-pay" x-data="{ method: @js($canCollect ? 'cash' : 'online') }">
                @if($canCollect || $canGateway)
                    <div class="segmented" role="tablist" aria-label="{{ __('manager_pos.payments.how') }}">
                        @if($canCollect)
                            <button type="button" role="tab" :aria-selected="method === 'cash' ? 'true' : 'false'" :aria-pressed="method === 'cash' ? 'true' : 'false'" x-on:click="method = 'cash'"><x-ui.icon name="wallet" size="14" />{{ __('manager_pos.method.cash') }}</button>
                            <button type="button" role="tab" :aria-selected="method === 'manual' ? 'true' : 'false'" :aria-pressed="method === 'manual' ? 'true' : 'false'" x-on:click="method = 'manual'"><x-ui.icon name="credit-card" size="14" />{{ __('manager_pos.method.manual_electronic') }}</button>
                        @endif
                        @if($canGateway)
                            <button type="button" role="tab" :aria-selected="method === 'online' ? 'true' : 'false'" :aria-pressed="method === 'online' ? 'true' : 'false'" x-on:click="method = 'online'"><x-ui.icon name="globe" size="14" />{{ __('manager_pos.method.gateway') }}</button>
                        @endif
                    </div>
                @endif

                @if($canCollect)
                    <form class="stack stack--sm" wire:submit="collectCash" x-show="method === 'cash'">
                        <div class="pay-form">
                            <input type="text" dir="ltr" inputmode="decimal" wire:model="cashAmount" placeholder="{{ __('Cash amount') }}" aria-label="{{ __('Cash amount') }}" required autocomplete="off">
                            <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="collectCash"><x-ui.icon name="wallet" size="16" />{{ __('Take cash') }}</button>
                        </div>
                        <button class="text-button pos-pay__fill" type="button" wire:click="fillRemaining('cashAmount')">{{ __('manager_pos.payments.fill_remaining', ['amount' => $summary['available_collectible']['formatted']]) }}</button>
                    </form>

                    <form class="pay-form pay-form--stack" wire:submit="collectManual" x-show="method === 'manual'" x-cloak>
                        <input type="text" dir="ltr" inputmode="decimal" wire:model="manualAmount" placeholder="{{ __('Amount') }}" aria-label="{{ __('Amount') }}" required autocomplete="off">
                        <button class="text-button pos-pay__fill" type="button" wire:click="fillRemaining('manualAmount')">{{ __('manager_pos.payments.fill_remaining', ['amount' => $summary['available_collectible']['formatted']]) }}</button>
                        <input type="text" wire:model="manualLabel" placeholder="{{ __('How — e.g. FIB transfer, bank transfer') }}" aria-label="{{ __('How') }}" maxlength="60" required>
                        <input type="text" dir="ltr" wire:model="manualReference" placeholder="{{ __('Reference (optional)') }}" aria-label="{{ __('Reference (optional)') }}" maxlength="120">
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="collectManual">{{ __('Record confirmed transfer') }}</button>
                    </form>
                @endif

                @if($canGateway)
                    <form class="pay-form pay-form--stack" wire:submit="startGateway" x-show="method === 'online'" @if($canCollect) x-cloak @endif>
                        <select wire:model="gatewayAccount" aria-label="{{ __('Online gateway') }}" required>
                            <option value="">{{ __('Online gateway') }}</option>
                            @foreach($gateways as $gateway)<option value="{{ $gateway['uuid'] }}">{{ $gateway['name'] }}</option>@endforeach
                        </select>
                        <input type="text" dir="ltr" inputmode="decimal" wire:model="gatewayAmount" placeholder="{{ __('Amount') }}" aria-label="{{ __('Amount') }}" required autocomplete="off">
                        <button class="text-button pos-pay__fill" type="button" wire:click="fillRemaining('gatewayAmount')">{{ __('manager_pos.payments.fill_remaining', ['amount' => $summary['available_collectible']['formatted']]) }}</button>
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="startGateway">{{ __('Start online payment') }}</button>
                    </form>
                @endif
            </div>
        @elseif($summary['collectible'])
            <p class="field-help">{{ __('manager_pos.payments.cannot_collect') }}</p>
        @endif

        @if(empty($payments))
            <p class="muted">{{ __('No payments yet.') }}</p>
        @else
            <ol class="timeline pos-money__timeline">
                @foreach($payments as $payment)
                    <li class="timeline__item" wire:key="payment-{{ $payment['uuid'] }}">
                        <span class="timeline__dot" data-tone="{{ $payment['tone'] }}" aria-hidden="true"><x-ui.icon name="dot" /></span>
                        <div class="timeline__body">
                            <div class="cluster cluster--between">
                                <strong dir="ltr" class="tabular">{{ $payment['amount']['formatted'] }}</strong>
                                <x-ui.status :tone="$payment['tone']" :label="$payment['status_label']" />
                            </div>
                            <p>{{ $payment['method_label'] }}@if($payment['manual_method_label'] !== null) — {{ $payment['manual_method_label'] }}@endif @if($payment['manual_reference'] !== null)<span dir="ltr" class="muted">· {{ $payment['manual_reference'] }}</span>@endif</p>
                            @if($payment['pending'])
                                @if($payment['provider_display_code'] !== null)
                                    <p>{{ __('Code') }}: <strong dir="ltr" class="mono">{{ $payment['provider_display_code'] }}</strong></p>
                                @endif
                                @if($payment['checkout_url'] !== null)
                                    <div class="copy-field">
                                        <code>{{ $payment['checkout_url'] }}</code>
                                        <button class="icon-button icon-button--sm" type="button" data-copy="{{ $payment['checkout_url'] }}" data-copied="{{ __('ui.actions.copied') }}" aria-label="{{ __('ui.actions.copy_link') }}"><x-ui.icon name="copy" /></button>
                                        <a class="icon-button icon-button--sm" href="{{ $payment['checkout_url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('ui.actions.open_new_tab') }}"><x-ui.icon name="external" /></a>
                                    </div>
                                @endif
                                @if($payment['expires_label'] !== null)
                                    <p class="cell-sub">{{ __('manager_pos.payments.expires', ['time' => $payment['expires_label']]) }}</p>
                                @endif
                            @endif
                            <div class="timeline__meta">{{ $payment['when'] }}@if($payment['collected_by']) · {{ $payment['collected_by'] }}@endif</div>
                            <div class="cluster cluster--tight">
                                @if($payment['pending'] && $canCollect)
                                    <button class="button button--ghost button--sm" type="button" wire:click="refresh('{{ $payment['uuid'] }}')" wire:loading.attr="data-loading" wire:target="refresh"><x-ui.icon name="refresh" size="14" />{{ __('Check status') }}</button>
                                    <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="cancel('{{ $payment['uuid'] }}')" wire:confirm="{{ __('Cancel this online payment?') }}" data-confirm-title="{{ __('manager_pos.payments.cancel_title') }}" data-confirm-tone="danger">{{ __('Cancel') }}</button>
                                @endif
                                @if($payment['can_refund'] && $canRefund)
                                    <button class="button button--ghost button--sm" type="button" wire:click="$set('refundPayment', '{{ $payment['uuid'] }}')"><x-ui.icon name="undo" size="14" />{{ __('Refund') }}</button>
                                    <span class="cell-sub">{{ __('manager_pos.payments.refundable', ['amount' => $payment['refundable']['formatted']]) }}</span>
                                @endif
                            </div>
                            @foreach($payment['refunds'] as $refund)
                                <div class="refund-line" wire:key="refund-{{ $refund['uuid'] }}">
                                    <span>{{ __('Refund') }} · {{ $refund['method_label'] }} · {{ $refund['when'] }}</span>
                                    <strong dir="ltr" class="text-danger">−{{ $refund['amount']['formatted'] }}</strong>
                                    <x-ui.status :tone="$refund['tone']" :label="$refund['status_label']" :dot="false" />
                                    @if($refund['reason'])<small class="muted">{{ $refund['reason'] }}</small>@endif
                                </div>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        @if($refundPayment !== '' && $canRefund)
            <form class="danger-zone stack stack--sm" wire:submit="refund">
                <strong>{{ __('Refund') }}</strong>
                <x-ui.field :label="__('Amount to refund')" for="refund-amount-{{ $this->getId() }}" required>
                    <input id="refund-amount-{{ $this->getId() }}" type="text" dir="ltr" inputmode="decimal" wire:model="refundAmount" required autocomplete="off">
                </x-ui.field>
                @if($refundMethods !== [])
                    <x-ui.field :label="__('Choose how the money goes back.')" for="refund-method-{{ $this->getId() }}" required>
                        <select id="refund-method-{{ $this->getId() }}" wire:model="refundMethod">
                            @foreach($refundMethods as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field :label="__('Reason')" for="refund-reason-{{ $this->getId() }}" required>
                    <input id="refund-reason-{{ $this->getId() }}" type="text" wire:model="refundReason" maxlength="190" required>
                </x-ui.field>
                <div class="cluster">
                    <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="refund">{{ __('Refund') }}</button>
                    <button class="button button--secondary" type="button" wire:click="$set('refundPayment', '')">{{ __('Cancel') }}</button>
                </div>
            </form>
        @endif
    @endif
</section>
