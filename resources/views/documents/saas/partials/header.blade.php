{{-- Logo, issuer and document title. $heading is the document title, $badge an optional status. --}}
@php
    $modern = $doc['layout'] === 'modern';
    $align = $doc['logo']['align'];
    $logoCell = ($doc['show']['logo'] ?? true)
        ? '<img src="'.e($doc['logo']['src']).'" alt="" style="height: '.(int) $doc['logo']['height'].'px; width: auto;">'
        : '';
    $issuerLines = array_filter([
        $issuer['address'] !== '' ? e($issuer['address']) : null,
        implode(' · ', array_filter([
            $issuer['phone'] !== '' ? '<span dir="ltr">'.e($issuer['phone']).'</span>' : null,
            $issuer['email'] !== '' ? '<span dir="ltr">'.e($issuer['email']).'</span>' : null,
            $issuer['website'] !== '' ? '<span dir="ltr">'.e($issuer['website']).'</span>' : null,
        ])) ?: null,
        ($doc['show']['tax_number'] ?? true) && $issuer['tax_number'] !== '' ? e(__('saas_documents.tax_number')).': <span dir="ltr">'.e($issuer['tax_number']).'</span>' : null,
    ]);
@endphp
@if($doc['texts']['header'] !== '')
    <p class="subtle center" style="margin: 0 0 8px;">{{ $doc['texts']['header'] }}</p>
@endif
@if($align === 'center')
    <table @class(['band' => $modern, 'rule' => ! $modern])>
        <tr><td class="center">{!! $logoCell !!}<div class="company" style="margin-top: 6px;">{{ $issuer['name'] }}</div>
            @foreach($issuerLines as $line)<div class="{{ $modern ? '' : 'muted' }}" style="font-size: 8.5pt;">{!! $line !!}</div>@endforeach
        </td></tr>
        <tr><td class="center" style="padding-top: 4px;"><span class="title">{{ $heading }}</span>@isset($badge) <span @class(['badge', 'void' => $badgeVoid ?? false])>{{ $badge }}</span>@endisset</td></tr>
    </table>
@else
    <table @class(['band' => $modern, 'rule' => ! $modern])>
        <tr>
            <td style="width: 55%;" class="{{ $align === 'end' ? 'end' : 'start' }}">
                @if($align === 'start'){!! $logoCell !!}@endif
                <div class="company" style="margin-top: 6px;">{{ $issuer['name'] }}</div>
                @foreach($issuerLines as $line)<div class="{{ $modern ? '' : 'muted' }}" style="font-size: 8.5pt;">{!! $line !!}</div>@endforeach
            </td>
            <td style="width: 45%;" class="end">
                @if($align === 'end'){!! $logoCell !!}<br>@endif
                <span class="title">{{ $heading }}</span><br>
                @isset($badge)<span @class(['badge', 'void' => $badgeVoid ?? false])>{{ $badge }}</span>@endisset
            </td>
        </tr>
    </table>
@endif
