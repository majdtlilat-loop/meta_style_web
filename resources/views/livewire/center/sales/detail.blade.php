{{-- One sale: what was charged, who did what and when, its invoice and money. --}}
<x-ui.drawer :title="$detail['invoice']['number'] ?? __('Draft sale')" :description="$detail['source_label']" close="closeDetail" size="lg">
    <div class="stack">
        @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
        @if($saved !== '')<div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>@endif

        <div class="cluster">
            <x-ui.status :value="$detail['status']" :label="$detail['status_label']" />
            <span class="chip" dir="ltr">{{ $detail['currency'] }}</span>
        </div>

        <dl class="kv-grid">
            <div><dt>{{ __('Customer') }}</dt><dd>{{ $detail['customer']['name'] ?? __('Walk-up customer') }}</dd></div>
            <div><dt>{{ __('manager_pos.history.cashier') }}</dt><dd>{{ $detail['finalized_by'] ?? $detail['created_by'] ?? '—' }}</dd></div>
            <div><dt>{{ __('manager_pos.history.issued_at') }}</dt><dd>{{ $detail['issued_at_label'] }}</dd></div>
            <div><dt>{{ __('manager_pos.history.opened_at') }}</dt><dd>{{ $detail['created_at_label'] }}</dd></div>
        </dl>

        <section class="drawer-section">
            <h3>{{ __('manager_pos.cart.lines') }}</h3>
            <ul class="line-items">
                @foreach($detail['lines'] as $line)
                    <li wire:key="detail-line-{{ $line['uuid'] }}">
                        <span>
                            <span class="tabular">{{ $line['quantity'] }} ×</span> {{ $line['name'] }}@if($line['variation'] !== null) <span class="muted">— {{ $line['variation'] }}</span>@endif
                            @foreach($line['addons'] as $addon)<small class="cell-sub">+ {{ $addon['name'] }}</small>@endforeach
                            @if($line['employee'] !== null)<small class="cell-sub">{{ __('manager_pos.cart.by', ['name' => $line['employee']['name']]) }}</small>@endif
                            @if($line['overridden'])<small class="cell-sub text-warning">{{ __('Price changed by :name: :reason', ['name' => $line['overridden_by'], 'reason' => $line['override_reason']]) }}</small>@endif
                        </span>
                        <span dir="ltr" class="tabular">{{ $line['line_subtotal']['formatted'] }}</span>
                    </li>
                @endforeach
            </ul>
            <dl class="summary-list">
                <div><dt>{{ __('Subtotal') }}</dt><dd dir="ltr">{{ $detail['subtotal']['formatted'] }}</dd></div>
                @foreach($detail['adjustments'] as $adjustment)
                    <div wire:key="detail-adjustment-{{ $adjustment['uuid'] }}">
                        <dt>{{ $adjustment['label'] }}@if($adjustment['reason'])<small class="cell-sub">{{ $adjustment['reason'] }}@if($adjustment['by']) · {{ $adjustment['by'] }}@endif</small>@endif</dt>
                        <dd dir="ltr" @class(['text-success' => $adjustment['is_discount']])>{{ $adjustment['sign'] }}{{ $adjustment['amount']['formatted'] }}</dd>
                    </div>
                @endforeach
                <div class="settlement__grand"><dt>{{ __('Total') }}</dt><dd dir="ltr">{{ $detail['grand_total']['formatted'] }}</dd></div>
            </dl>
            @if($detail['status'] === 'voided')
                <div class="notice" data-tone="warning" role="note">
                    <x-ui.icon name="alert-triangle" />
                    <p>{{ __('Voided by :name: :reason', ['name' => $detail['voided_by'], 'reason' => $detail['void_reason']]) }}<br><span class="cell-sub">{{ $detail['voided_at_label'] }}</span></p>
                </div>
            @endif
        </section>

        @if($detail['invoice'] !== null)
            <section class="drawer-section">
                <h3>{{ __('Invoice') }}</h3>
                {{-- Shown once: only a digest of the link is kept, so it cannot be looked up again. --}}
                @if($customerLink !== '')
                    <div class="copy-field">
                        <code>{{ $customerLink }}</code>
                        <button class="icon-button icon-button--sm" type="button" data-copy="{{ $customerLink }}" data-copied="{{ __('ui.actions.copied') }}" aria-label="{{ __('ui.actions.copy') }}"><x-ui.icon name="copy" /></button>
                        <a class="icon-button icon-button--sm" href="{{ $customerLink }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('Customer link') }}"><x-ui.icon name="external" /></a>
                    </div>
                @elseif($detail['invoice']['share_link_active'])
                    <p class="field-help">{{ __('manager_pos.history.link_active') }}</p>
                @endif
                <div class="cluster">
                    @if($canReissueLink)
                        @if($detail['invoice']['share_link_active'])
                            <button class="button button--secondary button--sm" type="button" wire:click="rotateLink" wire:confirm="{{ __('The current customer link will stop working. Continue?') }}" data-confirm-title="{{ __('Issue a new customer link') }}"><x-ui.icon name="link" size="16" />{{ __('Issue a new customer link') }}</button>
                        @else
                            <button class="button button--secondary button--sm" type="button" wire:click="rotateLink"><x-ui.icon name="link" size="16" />{{ __('Issue a customer link') }}</button>
                        @endif
                    @endif
                    @if($canPrint)
                        <a class="button button--ghost button--sm" href="{{ route('center.sales.invoice.print', ['uuid' => $detail['invoice']['uuid'], 'format' => '80mm']) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('Print receipt (80mm)') }}</a>
                        <a class="button button--ghost button--sm" href="{{ route('center.sales.invoice.print', ['uuid' => $detail['invoice']['uuid'], 'format' => 'a4']) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('Print A4') }}</a>
                    @endif
                </div>
            </section>

            @if($canViewMoney)
                <livewire:center.invoice-payments :invoice="$detail['invoice']['uuid']" :key="'money-'.$detail['invoice']['uuid']" />
            @endif
        @elseif($detail['status'] === 'draft' && $canSell)
            <div><a class="button button--secondary" href="{{ route('center.pos', ['sale' => $detail['uuid']]) }}" wire:navigate><x-ui.icon name="pos" size="16" />{{ __('Open at till') }}</a></div>
        @endif

        @if($detail['status'] === 'finalized' && $canVoid)
            <form class="drawer-section danger-zone" wire:submit="void" wire:confirm="{{ __('Void this sale? Its invoice is kept and marked void.') }}" data-confirm-title="{{ __('Void sale') }}" data-confirm-tone="danger">
                <h3>{{ __('Void sale') }}</h3>
                <p class="field-help">{{ __('manager_pos.history.void_help') }}</p>
                <x-ui.field :label="__('Reason for voiding')" for="void-reason" name="voidReason" required>
                    <input id="void-reason" type="text" wire:model="voidReason" maxlength="190" required autocomplete="off">
                </x-ui.field>
                <div><button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="void">{{ __('Void sale') }}</button></div>
            </form>
        @endif
    </div>
</x-ui.drawer>
