@props([
    'label',
    'buckets' => [],
    'series' => [],
    'currency' => null,
    'format' => null,
    'height' => '12rem',
])
@php
    /*
     * Column chart over ordered buckets (hours, days, months).
     *
     *   buckets  list of ['label' => string]
     *   series   list of ['label' => string, 'values' => list<int|float>]  (1 or 2)
     *   format   optional ValueFormat kind (docs/31-MANAGER-CHARTS.md)
     *
     * One series needs no legend — the card title names it. Two series carry a
     * legend and the validated rose / steel-blue pair (docs: dataviz palette).
     * Every bucket is a focusable hover target; the data table below makes
     * every value reachable without hovering.
     */
    $series = array_values($series);
    $max = 0;
    foreach ($series as $s) { $max = max($max, ...array_map('floatval', $s['values'] ?: [0])); }
    $scale = \App\View\Charts\Scale::nice((float) $max);
    $count = count($buckets);
    $every = $count > 10 ? (int) ceil($count / 8) : 1;
    // `format` (number | compact | money | percent | duration | seconds) is
    // optional: without it a currency means money, as before.
    $valueFormat = \App\View\Charts\ValueFormat::make($format, $currency);
    $format = static fn (float $value): string => $valueFormat->full($value);
    $tickLabel = static fn (float $value): string => $valueFormat->tick($value);
    $total = array_map(fn ($s) => array_sum(array_map('floatval', $s['values'])), $series);
@endphp
<figure {{ $attributes->class(['chart']) }} role="group" aria-label="{{ $label }}">
    @if(count($series) > 1)
        <ul class="chart__legend" aria-hidden="true">
            @foreach($series as $index => $s)
                <li><span class="chart__swatch" data-series="{{ $index + 1 }}"></span>{{ $s['label'] }}</li>
            @endforeach
        </ul>
    @endif
    <div class="chart__frame">
        <div class="chart__ticks" aria-hidden="true">
            @foreach(array_reverse($scale['ticks']) as $tick)
                <span style="inset-block-end: {{ round($tick / $scale['max'] * 100, 3) }}%">{{ $tickLabel($tick) }}</span>
            @endforeach
        </div>
        <div class="chart__plot" style="--chart-height: {{ $height }}">
            @foreach($scale['ticks'] as $tick)
                <span class="chart__gridline" style="inset-block-end: {{ round($tick / $scale['max'] * 100, 3) }}%" aria-hidden="true"></span>
            @endforeach
            <div class="chart__columns">
                @foreach($buckets as $i => $bucket)
                    @php
                        $rows = array_map(fn ($s, $index) => ['series' => $index + 1, 'label' => $s['label'], 'value' => $format((float) ($s['values'][$i] ?? 0))], $series, array_keys($series));
                    @endphp
                    <div class="chart__bucket" tabindex="0" data-tip data-tip-title="{{ $bucket['label'] }}" data-tip-rows='@json($rows)'
                         aria-label="{{ $bucket['label'] }}: {{ collect($rows)->map(fn ($r) => (count($series) > 1 ? $r['label'].' ' : '').$r['value'])->implode(', ') }}">
                        @foreach($series as $index => $s)
                            @php $value = (float) ($s['values'][$i] ?? 0); @endphp
                            <span class="chart__bar" data-series="{{ $index + 1 }}" style="block-size: {{ $scale['max'] > 0 ? round($value / $scale['max'] * 100, 3) : 0 }}%" @if($value <= 0) data-empty @endif></span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        <div class="chart__axis" aria-hidden="true">
            @foreach($buckets as $i => $bucket)
                <span>@if($i % $every === 0){{ $bucket['label'] }}@endif</span>
            @endforeach
        </div>
    </div>
    <details class="chart__data">
        <summary>{{ __('ui.chart.show_data') }}</summary>
        <div class="table-shell">
            <table>
                <thead><tr><th scope="col">{{ __('ui.chart.period') }}</th>@foreach($series as $s)<th scope="col" class="numeric">{{ $s['label'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($buckets as $i => $bucket)
                        <tr><th scope="row">{{ $bucket['label'] }}</th>@foreach($series as $s)<td class="numeric">{{ $format((float) ($s['values'][$i] ?? 0)) }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row">{{ __('ui.chart.total') }}</th>@foreach($total as $sum)<td class="numeric"><strong>{{ $format($sum) }}</strong></td>@endforeach</tr></tfoot>
            </table>
        </div>
    </details>
</figure>
