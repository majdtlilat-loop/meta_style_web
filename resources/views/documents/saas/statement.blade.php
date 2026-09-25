{{--
    A center's SaaS account statement: platform subscription billing only, never
    the center's own point-of-sale finance. One section per currency — amounts
    in different currencies are never added together.
--}}
@extends('documents.saas.layout')

@section('document')
    @include('documents.saas.partials.header', ['heading' => __('saas_documents.statement.title')])

    <div class="spacer"></div>
    <table>
        <tr>
            <td style="width: 50%; padding-inline-end: 8px;">
                <table class="box">
                    <tr><td>
                        <div class="label">{{ __('saas_documents.statement.account') }}</div>
                        <div class="strong" style="font-size: 11pt;">{{ $center['name'] }}</div>
                        @if($doc['show']['center_contact'] ?? true)
                            @isset($center['contact']['name'])<div class="muted">{{ $center['contact']['name'] }}</div>@endisset
                            @isset($center['contact']['email'])<div class="muted" dir="ltr">{{ $center['contact']['email'] }}</div>@endisset
                            @isset($center['contact']['phone'])<div class="muted" dir="ltr">{{ $center['contact']['phone'] }}</div>@endisset
                        @endif
                    </td></tr>
                </table>
            </td>
            <td style="width: 50%; padding-inline-start: 8px;">
                <table class="box">
                    <tr><td class="label">{{ __('saas_documents.statement.period') }}</td><td class="end">{{ $statement['from'] }} – {{ $statement['to'] }}</td></tr>
                    <tr><td class="label">{{ __('saas_documents.statement.generated') }}</td><td class="end">{{ $statement['generated'] }}</td></tr>
                    @foreach($statement['filters'] as $filter => $value)
                        <tr><td class="label">{{ __('saas_documents.statement.filters.'.$filter) }}</td><td class="end">{{ $value }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    @if($statement['filtered'])
        <div class="spacer"></div>
        <div class="note subtle">{{ __('saas_documents.statement.filtered_note') }}</div>
    @endif

    @forelse($statement['groups'] as $group)
        <div class="spacer"></div>
        <table>
            <tr>
                <td class="strong" style="font-size: 12pt; padding-bottom: 4px;">{{ __('saas_documents.statement.currency_section', ['currency' => $group['currency']]) }}</td>
            </tr>
        </table>
        <table class="box">
            <tr>
                @if($group['opening'] !== null)
                    <td><div class="label">{{ __('saas_documents.statement.opening') }}</div><div class="num strong" dir="ltr">{{ $group['opening'] }}</div></td>
                @endif
                <td><div class="label">{{ __('saas_documents.statement.invoiced') }}</div><div class="num" dir="ltr">{{ $group['totals']['invoiced'] }}</div></td>
                <td><div class="label">{{ __('saas_documents.statement.discounts') }}</div><div class="num" dir="ltr">{{ $group['totals']['discounts'] }}</div></td>
                <td><div class="label">{{ __('saas_documents.statement.voided') }}</div><div class="num" dir="ltr">{{ $group['totals']['voided'] }}</div></td>
                <td><div class="label">{{ __('saas_documents.statement.paid') }}</div><div class="num" dir="ltr">{{ $group['totals']['paid'] }}</div></td>
                <td><div class="label">{{ __('saas_documents.statement.reversed') }}</div><div class="num" dir="ltr">{{ $group['totals']['reversed'] }}</div></td>
                @if($group['closing'] !== null)
                    <td><div class="label">{{ __('saas_documents.statement.closing') }}</div><div class="num strong" dir="ltr">{{ $group['closing'] }}</div></td>
                @endif
            </tr>
        </table>

        <div class="spacer"></div>
        <table class="lines">
            <thead>
                <tr>
                    <th class="start">{{ __('saas_documents.statement.date') }}</th>
                    <th class="start">{{ __('saas_documents.statement.type') }}</th>
                    <th class="start">{{ __('saas_documents.statement.invoice') }}</th>
                    <th class="start">{{ __('saas_documents.statement.description') }}</th>
                    <th class="end">{{ __('saas_documents.statement.debit') }}</th>
                    <th class="end">{{ __('saas_documents.statement.credit') }}</th>
                    @if($group['closing'] !== null)<th class="end">{{ __('saas_documents.statement.balance') }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @if($group['opening'] !== null)
                    <tr>
                        <td>{{ $statement['from'] }}</td>
                        <td colspan="5" class="muted">{{ __('saas_documents.statement.opening') }}</td>
                        <td class="end num" dir="ltr">{{ $group['opening'] }}</td>
                    </tr>
                @endif
                @forelse($group['lines'] as $line)
                    <tr>
                        <td style="white-space: nowrap;">{{ $line['date'] }}</td>
                        <td>{{ $line['type'] }}</td>
                        <td dir="ltr" class="start">{{ $line['invoice'] ?? '—' }}@if(filled($line['reference']) && $line['reference'] !== $line['invoice'])<div class="subtle">{{ $line['reference'] }}</div>@endif</td>
                        <td>{{ $line['description'] !== '' ? $line['description'] : '—' }}</td>
                        <td class="end num" dir="ltr">{{ $line['debit'] ?? '' }}</td>
                        <td class="end num" dir="ltr">{{ $line['credit'] ?? '' }}</td>
                        @if($group['closing'] !== null)<td class="end num" dir="ltr">{{ $line['balance'] ?? '' }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="7" class="center muted">{{ __('saas_documents.statement.no_lines') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @empty
        <div class="spacer"></div>
        <div class="note center muted">{{ __('saas_documents.statement.empty') }}</div>
    @endforelse

    <div class="spacer"></div>
    <div class="footer center">
        @if($doc['texts']['footer'] !== '')<div style="white-space: pre-line;">{{ $doc['texts']['footer'] }}</div>@endif
        <div>{{ __('saas_documents.statement.scope_note') }}</div>
    </div>
@endsection
