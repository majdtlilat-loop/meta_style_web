{{-- A published invoice: its link (once), its paper, and taking the money. --}}
<div class="invoice-issued stack stack--sm pos-issued">
    <div class="notice" data-tone="success">
        <x-ui.icon name="check-circle" />
        <p><strong dir="ltr">{{ __('Invoice :number', ['number' => $cart['invoice']['number']]) }}</strong><br><span class="cell-sub">{{ $cart['issued_at_label'] }}</span></p>
    </div>

    {{-- Shown once: only a digest of the link is kept. A new link is issued from Sales. --}}
    @if($customerLink !== '')
        <div class="stack stack--sm">
            <span class="cell-sub">{{ __('manager_pos.issued.link_once') }}</span>
            <div class="copy-field">
                <code>{{ $customerLink }}</code>
                <button class="icon-button icon-button--sm" type="button" data-copy="{{ $customerLink }}" data-copied="{{ __('ui.actions.copied') }}" aria-label="{{ __('ui.actions.copy') }}"><x-ui.icon name="copy" /></button>
                <a class="icon-button icon-button--sm" href="{{ $customerLink }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('Customer link') }}"><x-ui.icon name="external" /></a>
            </div>
        </div>
    @endif

    <div class="cluster">
        @if($canPrint)
            <a class="button button--secondary button--sm" href="{{ route('center.sales.invoice.print', ['uuid' => $cart['invoice']['uuid'], 'format' => '80mm']) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('Print receipt (80mm)') }}</a>
            <a class="button button--ghost button--sm" href="{{ route('center.sales.invoice.print', ['uuid' => $cart['invoice']['uuid'], 'format' => 'a4']) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('Print A4') }}</a>
        @endif
        <a class="button button--ghost button--sm" href="{{ route('center.sales', ['sale' => $cart['uuid']]) }}" wire:navigate><x-ui.icon name="sales" size="16" />{{ __('manager_pos.issued.open_in_sales') }}</a>
        @if($canCreate)
            <button class="button button--sm" type="button" wire:click="newSale"><x-ui.icon name="plus" size="16" />{{ __('New sale') }}</button>
        @endif
    </div>

    {{-- Total, paid, pending, remaining — and taking the money, split or whole. --}}
    @if($canViewMoney)
        <livewire:center.invoice-payments :invoice="$cart['invoice']['uuid']" :key="'money-'.$cart['invoice']['uuid']" />
    @endif
</div>
