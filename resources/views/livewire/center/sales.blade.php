{{--
    A branch's sales for a day, and the invoice behind each.

    docs/18-SALES.md §45. Not a finance report: no revenue breakdown, no payment
    method. The list a manager opens to find a sale, share its invoice or void it.
--}}
<div>
    <x-center-nav />

    <h1>{{ __('Sales') }}</h1>

    @if ($error !== '')
        <p role="alert" class="error">{{ $error }}</p>
    @endif

    @if ($saved !== '')
        <p role="status">{{ $saved }}</p>
    @endif

    {{-- Issued sales stay readable after a downgrade; new ones need the POS. --}}
    @unless ($hasPos)
        <p role="note" class="notice">{{ __('Point of sale is not included for this center. Past sales and invoices remain available to read.') }}</p>
    @endunless

    <form onsubmit="return false" class="filters">
        <label>
            {{ __('Branch') }}
            <select wire:model.live="branch">
                @foreach ($branches as $option)
                    <option value="{{ $option->uuid }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>

        <label>
            {{ __('Date') }}
            <input type="date" wire:model.live="date">
        </label>

        <label>
            {{ __('Status') }}
            <select wire:model.live="status">
                <option value="">{{ __('Issued and voided') }}</option>
                <option value="finalized">{{ __('Issued') }}</option>
                <option value="voided">{{ __('Voided') }}</option>
                <option value="draft">{{ __('Open drafts') }}</option>
            </select>
        </label>
    </form>

    <table>
        <thead>
            <tr>
                <th scope="col">{{ __('Invoice') }}</th>
                <th scope="col">{{ __('Customer') }}</th>
                <th scope="col">{{ __('Status') }}</th>
                <th scope="col">{{ __('Total') }}</th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sales as $row)
                <tr wire:key="sale-{{ $row['uuid'] }}">
                    <td>{{ $row['invoice']['number'] ?? '—' }}</td>
                    <td>{{ $row['customer']['name'] ?? __('Walk-up customer') }}</td>
                    <td>{{ __($row['status']) }}</td>
                    <td>{{ $row['grand_total']['formatted'] }}</td>
                    <td>
                        <button type="button" wire:click="show('{{ $row['uuid'] }}')">{{ __('Details') }}</button>
                        @if ($row['status'] === 'draft' && $hasPos)
                            <a href="{{ route('center.pos', ['sale' => $row['uuid']]) }}" wire:navigate>{{ __('Open at till') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('No sales.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($detail !== null)
        <section class="detail">
            <h2>{{ $detail['invoice']['number'] ?? __('Draft sale') }}</h2>

            <ul>
                @foreach ($detail['lines'] as $line)
                    <li>
                        {{ $line['quantity'] }} × {{ $line['name'] }}
                        @if ($line['variation'] !== null) — {{ $line['variation'] }} @endif
                        · {{ $line['line_subtotal']['formatted'] }}
                        @if ($line['overridden'])
                            <small>{{ __('Price changed by :name: :reason', ['name' => $line['overridden_by'], 'reason' => $line['override_reason']]) }}</small>
                        @endif
                    </li>
                @endforeach
            </ul>

            <p><strong>{{ __('Total') }}:</strong> {{ $detail['grand_total']['formatted'] }}</p>

            @if ($detail['status'] === 'voided')
                <p>{{ __('Voided by :name: :reason', ['name' => $detail['voided_by'], 'reason' => $detail['void_reason']]) }}</p>
            @endif

            @if ($detail['invoice'] !== null)
                {{-- Shown once: only a digest of the link is kept, so it cannot be looked up again. --}}
                @if ($customerLink !== '')
                    <p><a href="{{ $customerLink }}" target="_blank" rel="noopener noreferrer">{{ __('Customer link') }}</a></p>
                @endif

                @if ($canReissueLink)
                    @if ($detail['invoice']['share_link_active'])
                        <button type="button" wire:click="rotateLink" wire:confirm="{{ __('The current customer link will stop working. Continue?') }}">
                            {{ __('Issue a new customer link') }}
                        </button>
                    @else
                        <button type="button" wire:click="rotateLink">{{ __('Issue a customer link') }}</button>
                    @endif
                @endif

                @if ($canPrint)
                    <a href="{{ route('center.sales.invoice.print', ['uuid' => $detail['invoice']['uuid'], 'format' => '80mm']) }}" target="_blank">{{ __('Print receipt (80mm)') }}</a>
                    <a href="{{ route('center.sales.invoice.print', ['uuid' => $detail['invoice']['uuid'], 'format' => 'a4']) }}" target="_blank">{{ __('Print A4') }}</a>
                @endif
            @endif

            @if ($detail['status'] === 'finalized' && $canVoid)
                <form wire:submit="void" class="void">
                    <input type="text" wire:model="voidReason" placeholder="{{ __('Reason for voiding') }}" maxlength="190" required>
                    <button type="submit" wire:confirm="{{ __('Void this sale? Its invoice is kept and marked void.') }}">{{ __('Void sale') }}</button>
                </form>
            @endif
        </section>
    @endif

    {{-- Branch configuration: the start of this branch's invoice numbers.
         Applies to invoices issued from now on (docs/18-SALES.md §17). --}}
    @if ($canSetPrefix && $branch !== '')
        <section class="invoice-prefix">
            <h2>{{ __('Invoice numbering') }}</h2>
            <p>{{ __('Current prefix: :prefix', ['prefix' => $currentPrefix ?? __('none (the main branch uses INV)')]) }}</p>
            <form wire:submit="setInvoicePrefix">
                <input type="text" wire:model="invoicePrefix" maxlength="4" placeholder="{{ __('e.g. BG') }}">
                <button type="submit">{{ __('Save prefix') }}</button>
            </form>
        </section>
    @endif

    {{-- The small product catalog the till sells from. No stock (§8). --}}
    @if ($canManageProducts)
        <section class="products">
            <h2>{{ __('Products') }}</h2>

            <form wire:submit="addProduct">
                <input type="text" wire:model="productName" placeholder="{{ __('Name') }}" maxlength="120" required>
                <input type="text" inputmode="decimal" wire:model="productPrice" placeholder="{{ __('Price') }}" required>
                <input type="text" wire:model="productBarcode" placeholder="{{ __('Barcode (optional)') }}" maxlength="64">
                <input type="text" wire:model="productSku" placeholder="{{ __('SKU (optional)') }}" maxlength="64">
                <button type="submit">{{ __('Add product') }}</button>
            </form>

            <ul>
                @forelse ($products as $product)
                    <li wire:key="product-{{ $product->uuid }}">
                        {{ $product->name }} · {{ $product->price()->formatted() }}
                        @if ($product->barcode !== null) · <span dir="ltr">{{ $product->barcode }}</span> @endif
                        <button type="button" wire:click="archiveProduct('{{ $product->uuid }}')"
                                wire:confirm="{{ __('Archive this product? Past sales keep it.') }}">{{ __('Archive') }}</button>
                    </li>
                @empty
                    <li>{{ __('No products yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
