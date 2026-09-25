@props([
    'label',
    'items' => [],
    'currency' => null,
    'format' => null,
    'empty' => null,
])
@php
    /*
     * Ranked horizontal bars: compare magnitude across named categories.
     * One hue — the rows are labelled, so colour never carries identity.
     *
     *   items  list of ['label' => string, 'value' => int|float, 'href' => ?string, 'meta' => ?string]
     *   format optional ValueFormat kind; for previous ticks and "Other", use x-chart.ranked
     */
    $max = max(1, ...array_map(fn ($item) => (float) $item['value'], $items ?: [['value' => 0]]));
    $total = array_sum(array_map(fn ($item) => (float) $item['value'], $items));
    // `format` is optional: without it a currency means money, as before.
    $valueFormat = \App\View\Charts\ValueFormat::make($format, $currency);
    $format = static fn (float $value): string => $valueFormat->full($value);
@endphp
@if($items === [])
    <p class="chart__empty">{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <ul {{ $attributes->class(['hbars']) }} aria-label="{{ $label }}">
        @foreach($items as $item)
            @php $value = (float) $item['value']; $share = $total > 0 ? round($value / $total * 100) : 0; @endphp
            <li class="hbars__row">
                <span class="hbars__label">
                    @if(! empty($item['href']))<a href="{{ $item['href'] }}" wire:navigate>{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif
                    @if(! empty($item['meta']))<small>{{ $item['meta'] }}</small>@endif
                </span>
                <span class="hbars__track" aria-hidden="true"><span style="inline-size: {{ round($value / $max * 100, 2) }}%"></span></span>
                <span class="hbars__value"><strong>{{ $format($value) }}</strong><small>{{ $share }}%</small></span>
            </li>
        @endforeach
    </ul>
@endif
