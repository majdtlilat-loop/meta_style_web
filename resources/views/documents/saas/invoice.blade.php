{{-- A SaaS invoice. Every amount is pre-formatted by SaasBillingDocuments from the invoice row. --}}
@extends('documents.saas.layout')

@section('document')
    @php($show = $doc['show'])
    @if($sample)
        <div class="sample">{{ __('saas_documents.sample.badge') }}</div>
        <div class="spacer"></div>
    @endif

    @include('documents.saas.partials.header', ['heading' => __('saas_documents.invoice.title'), 'badge' => $invoice['status_label'], 'badgeVoid' => $invoice['void']])

    <div class="spacer"></div>
    <table>
        <tr>
            <td style="width: 50%; padding-inline-end: 8px;">
                <table class="box">
                    <tr><td>
                        <div class="label">{{ __('saas_documents.invoice.bill_to') }}</div>
                        <div class="strong" style="font-size: 11pt;">{{ $center['name'] }}</div>
                        @if($show['center_contact'] ?? true)
                            @isset($center['contact']['name'])<div class="muted">{{ $center['contact']['name'] }}</div>@endisset
                            @isset($center['contact']['email'])<div class="muted" dir="ltr">{{ $center['contact']['email'] }}</div>@endisset
                            @isset($center['contact']['phone'])<div class="muted" dir="ltr">{{ $center['contact']['phone'] }}</div>@endisset
                        @endif
                    </td></tr>
                </table>
            </td>
            <td style="width: 50%; padding-inline-start: 8px;">
                <table class="box">
                    <tr><td class="label">{{ __('saas_documents.invoice.number') }}</td><td class="end strong" dir="ltr">{{ $invoice['number'] }}</td></tr>
                    <tr><td class="label">{{ __('saas_documents.invoice.issued') }}</td><td class="end">{{ $invoice['issued'] }}</td></tr>
                    <tr><td class="label">{{ __('saas_documents.invoice.due') }}</td><td class="end">{{ $invoice['due'] }}</td></tr>
                    <tr><td class="label">{{ __('saas_documents.invoice.currency') }}</td><td class="end" dir="ltr">{{ $invoice['currency'] }}</td></tr>
                    @if(($show['reference'] ?? true) && filled($invoice['reference']))
                        <tr><td class="label">{{ __('saas_documents.invoice.reference') }}</td><td class="end" dir="ltr">{{ $invoice['reference'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="spacer"></div>
    <table class="lines">
        <thead>
            <tr>
                <th class="start">{{ __('saas_documents.invoice.description') }}</th>
                @if($show['period'] ?? true)<th class="start">{{ __('saas_documents.invoice.period') }}</th>@endif
                <th class="end">{{ __('saas_documents.invoice.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="strong">{{ $invoice['plan'] !== '' ? $invoice['plan'] : __('saas_documents.invoice.subscription') }}</div>
                    @if(($show['cycle'] ?? true) && $invoice['cycle'])<div class="subtle">{{ __('saas_documents.invoice.cycle') }}: {{ $invoice['cycle'] }}</div>@endif
                </td>
                @if($show['period'] ?? true)<td>{{ $invoice['period'] ?? '—' }}</td>@endif
                <td class="end num" dir="ltr">{{ $invoice['subtotal'] }}</td>
            </tr>
        </tbody>
    </table>

    <table>
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                <table class="totals">
                    <tr><td class="muted">{{ __('saas_documents.invoice.subtotal') }}</td><td class="end num" dir="ltr">{{ $invoice['subtotal'] }}</td></tr>
                    @if($invoice['discount'] !== null)
                        <tr><td class="muted">{{ __('saas_documents.invoice.discount') }}</td><td class="end num" dir="ltr">− {{ $invoice['discount'] }}</td></tr>
                    @endif
                    <tr class="grand"><td>{{ __('saas_documents.invoice.total') }}</td><td class="end num" dir="ltr">{{ $invoice['total'] }}</td></tr>
                    <tr><td class="muted">{{ __('saas_documents.invoice.paid') }}</td><td class="end num" dir="ltr">{{ $invoice['paid'] }}</td></tr>
                    <tr><td class="strong">{{ $invoice['void'] ? __('saas_documents.invoice.void_balance') : __('saas_documents.invoice.balance') }}</td><td class="end num strong" dir="ltr">{{ $invoice['void'] ? '—' : $invoice['balance'] }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if(($show['payments'] ?? true) && $invoice['payments'] !== [])
        <div class="spacer"></div>
        <div class="label" style="margin-bottom: 4px;">{{ __('saas_documents.invoice.payments') }}</div>
        <table class="lines">
            <thead>
                <tr>
                    <th class="start">{{ __('saas_documents.invoice.payment_date') }}</th>
                    <th class="start">{{ __('saas_documents.invoice.payment_method') }}</th>
                    <th class="start">{{ __('saas_documents.invoice.reference') }}</th>
                    <th class="end">{{ __('saas_documents.invoice.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice['payments'] as $payment)
                    <tr>
                        <td>{{ $payment['date'] }}</td>
                        <td>{{ $payment['method'] }}@if($payment['reversed']) <span class="subtle">({{ __('saas_documents.invoice.reversed') }})</span>@endif</td>
                        <td dir="ltr" class="start">{{ $payment['reference'] ?: '—' }}</td>
                        <td class="end num" dir="ltr"><span @class(['reversed' => $payment['reversed']])>{{ $payment['amount'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(($show['notes'] ?? true) && (filled($invoice['notes']) || $doc['texts']['notes'] !== ''))
        <div class="spacer"></div>
        <div class="note">
            <div class="label">{{ __('saas_documents.invoice.notes') }}</div>
            @if(filled($invoice['notes']))<div>{{ $invoice['notes'] }}</div>@endif
            @if($doc['texts']['notes'] !== '')<div class="muted">{{ $doc['texts']['notes'] }}</div>@endif
        </div>
    @endif

    @if(($show['payment_instructions'] ?? true) && $doc['texts']['payment_instructions'] !== '' && ! $invoice['void'])
        <div class="spacer"></div>
        <div class="note">
            <div class="label">{{ __('saas_documents.invoice.payment_instructions') }}</div>
            <div style="white-space: pre-line;">{{ $doc['texts']['payment_instructions'] }}</div>
        </div>
    @endif

    <div class="spacer"></div>
    <div class="footer center">
        @if($doc['texts']['footer'] !== '')<div style="white-space: pre-line;">{{ $doc['texts']['footer'] }}</div>@endif
        <div>{{ $issuer['name'] }} · <span dir="ltr">{{ $invoice['number'] }}</span></div>
    </div>
@endsection
