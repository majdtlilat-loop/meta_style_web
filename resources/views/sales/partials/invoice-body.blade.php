{{--
    The invoice, as a customer may see it. Shared by the digital page, the 80mm
    printout and the A4 page — three LAYOUTS of one allow-listed view model, not
    three documents (docs/18-SALES.md §22).

    Everything here comes from `InvoiceRenderer::document()`, which is built only
    from the invoice's own immutable snapshots. No ids, no staff names, no notes,
    no void reason, no customer phone (§§27, 54).

    Logical properties only, so Arabic and Kurdish render right-to-left through
    the same markup.
--}}
<div class="invoice">
    @if ($invoice['voided'])
        <div class="void-banner" role="note">
            <strong>{{ __('invoice_public.voided') }}</strong>
            @if ($invoice['voided_date'] !== null)
                <span>{{ __('invoice_public.voided_on', ['date' => $invoice['voided_date']]) }}</span>
            @endif
        </div>
    @endif

    <header class="identity">
        <div class="center">{{ $invoice['center_name'] }}</div>
        <div class="branch">{{ $invoice['branch_name'] }}</div>
        @if ($invoice['branch_address'] !== null)
            <div class="address">{{ $invoice['branch_address'] }}</div>
        @endif
        @if ($invoice['branch_phone'] !== null)
            <div class="phone" dir="ltr">{{ $invoice['branch_phone'] }}</div>
        @endif
    </header>

    <dl class="meta">
        <div><dt>{{ __('invoice_public.number') }}</dt><dd dir="ltr">{{ $invoice['number'] }}</dd></div>
        <div><dt>{{ __('invoice_public.date') }}</dt><dd dir="ltr">{{ $invoice['issued_date'] }}</dd></div>
        <div><dt>{{ __('invoice_public.time') }}</dt><dd dir="ltr">{{ $invoice['issued_time'] }}</dd></div>
        @if ($invoice['customer_name'] !== null)
            <div><dt>{{ __('invoice_public.customer') }}</dt><dd>{{ $invoice['customer_name'] }}</dd></div>
        @endif
    </dl>

    <table class="lines">
        <thead>
            <tr>
                <th scope="col">{{ __('invoice_public.item') }}</th>
                <th scope="col" class="num">{{ __('invoice_public.quantity') }}</th>
                <th scope="col" class="num">{{ __('invoice_public.unit_price') }}</th>
                <th scope="col" class="num">{{ __('invoice_public.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr>
                    <td>
                        {{ $line['name'] }}
                        @if ($line['variation'] !== null)
                            <span class="variation">— {{ $line['variation'] }}</span>
                        @endif
                        @foreach ($line['addons'] as $addon)
                            <div class="addon">+ {{ $addon['name'] }}</div>
                        @endforeach
                    </td>
                    <td class="num" dir="ltr">{{ $line['quantity'] }}</td>
                    <td class="num" dir="ltr">{{ $line['unit_price']['formatted'] }}</td>
                    <td class="num" dir="ltr">{{ $line['line_subtotal']['formatted'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <dl class="totals">
        <div><dt>{{ __('invoice_public.subtotal') }}</dt><dd dir="ltr">{{ $invoice['subtotal']['formatted'] }}</dd></div>

        @foreach ($invoice['adjustments'] as $adjustment)
            <div>
                <dt>
                    {{ $adjustment['kind'] === 'discount' ? __('invoice_public.discount') : __('invoice_public.surcharge') }}
                    @if ($adjustment['percent'] !== null)
                        <span dir="ltr">({{ $adjustment['percent'] }}%)</span>
                    @endif
                </dt>
                <dd dir="ltr">{{ $adjustment['amount']['formatted'] }}</dd>
            </div>
        @endforeach

        @if ($invoice['tax_total']['amount'] !== 0)
            <div><dt>{{ __('invoice_public.tax') }}</dt><dd dir="ltr">{{ $invoice['tax_total']['formatted'] }}</dd></div>
        @endif

        <div class="grand"><dt>{{ __('invoice_public.total') }}</dt><dd dir="ltr">{{ $invoice['grand_total']['formatted'] }}</dd></div>
    </dl>

    <footer class="thanks">{{ __('invoice_public.thank_you') }}</footer>
</div>
