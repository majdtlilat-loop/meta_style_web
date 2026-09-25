@props([
    'label',
    'buckets' => [],
    'series' => [],
    'format' => null,
    'currency' => null,
    'totals' => null,
    'height' => '12rem',
    'empty' => null,
])
{{--
    A trend OVERLAY: 2–4 series on one time axis (branch vs branch, employee
    vs employee), one unit, one y-axis, a legend, one crosshair tooltip per
    bucket listing every series. Past four, three stay and the rest sum into
    Other. App\View\Charts\MultiLineChart; docs/31-MANAGER-CHARTS.md.
--}}
@php($chart = \App\View\Charts\MultiLineChart::build($label, $buckets, $series, $format, $currency, $totals))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--line', 'chart--multiline']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        <ul class="chart__legend" aria-hidden="true">
            @foreach($chart['legend'] as $entry)
                <li><span class="chart__key chart__key--line" data-series="{{ $entry['series'] }}"></span>{{ $entry['label'] }}</li>
            @endforeach
        </ul>
        <div class="chart__frame">
            <div class="chart__ticks" aria-hidden="true">
                @foreach($chart['ticks'] as $tick)
                    <span style="inset-block-end: {{ $tick['pos'] }}%">{{ $tick['label'] }}</span>
                @endforeach
            </div>
            <div class="chart__plot" style="--chart-height: {{ $height }}">
                @foreach($chart['ticks'] as $tick)
                    <span class="chart__gridline" style="inset-block-end: {{ $tick['pos'] }}%" aria-hidden="true"></span>
                @endforeach
                @if($chart['zero'] !== null)
                    <span class="chart__zero" style="inset-block-end: {{ $chart['zero'] }}%" aria-hidden="true"></span>
                @endif
                <svg class="chart__marks" viewBox="{{ $chart['view_box'] }}" preserveAspectRatio="none" role="img" aria-label="{{ $chart['summary'] }}" focusable="false">
                    @foreach($chart['paths'] as $path)
                        @if($path['d'] !== '')<path class="chart__line" data-series="{{ $path['series'] }}" style="stroke: var(--mark)" d="{{ $path['d'] }}" />@endif
                    @endforeach
                </svg>
                <div class="chart__hits" role="group" aria-label="{{ $label }}" x-data="{{ \App\View\Charts\Keyboard::ALPINE }}" x-on:keydown="nav($event)">
                    @foreach($chart['points'] as $point)
                        <span class="chart__hit" role="img" data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}"
                              data-tip data-tip-title="{{ $point['label'] }}" data-tip-rows='@json($point['rows'])' aria-label="{{ $point['aria'] }}">
                            @foreach($point['dots'] as $dot)
                                <span class="chart__dot" data-series="{{ $dot['series'] }}" style="inset-block-end: {{ $dot['pos'] }}%"></span>
                            @endforeach
                        </span>
                    @endforeach
                </div>
            </div>
            <div class="chart__axis" aria-hidden="true">
                @foreach($chart['axis'] as $tick)
                    <span @if($tick['thin']) data-thin @endif>@if($tick['show']){{ $tick['label'] }}@endif</span>
                @endforeach
            </div>
        </div>
        <details class="chart__data">
            <summary>{{ __('ui.chart.show_data') }}</summary>
            <div class="table-shell">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('ui.chart.period') }}</th>
                            @foreach($chart['legend'] as $entry)<th scope="col" class="numeric">{{ $entry['label'] }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart['table'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach($row['values'] as $value)<td class="numeric">{{ $value }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if($chart['totals'])
                        <tfoot>
                            <tr>
                                <th scope="row">{{ __('ui.chart.total') }}</th>
                                @foreach($chart['totals'] as $total)<td class="numeric"><strong>{{ $total }}</strong></td>@endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </details>
    </figure>
@endif
