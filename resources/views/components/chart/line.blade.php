@props([
    'label',
    'buckets' => [],
    'series' => [],
    'previous' => null,
    'area' => false,
    'format' => null,
    'currency' => null,
    'totals' => null,
    'height' => '12rem',
    'empty' => null,
])
{{--
    A time series with an optional previous period (dashed, muted) and an
    optional area wash. Geometry and formatting: App\View\Charts\LineChart.
    docs/31-MANAGER-CHARTS.md has the props and data shapes.
--}}
@php($chart = \App\View\Charts\LineChart::build($label, $buckets, $series, $previous, $format, $currency, (bool) $area, $totals))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--line']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        @if($chart['has_previous'])
            <ul class="chart__legend" aria-hidden="true">
                <li><span class="chart__key chart__key--line" data-series="1"></span>{{ $chart['series_label'] }}</li>
                <li><span class="chart__key chart__key--dashed" data-series="previous"></span>{{ $chart['previous_label'] }}</li>
            </ul>
        @endif
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
                    @if($chart['area'] !== '')<path class="chart__area" d="{{ $chart['area'] }}" />@endif
                    @if($chart['previous_line'] !== '')<path class="chart__line chart__line--previous" d="{{ $chart['previous_line'] }}" />@endif
                    <path class="chart__line" d="{{ $chart['line'] }}" />
                </svg>
                <div class="chart__hits" role="group" aria-label="{{ $label }}" x-data="{{ \App\View\Charts\Keyboard::ALPINE }}" x-on:keydown="nav($event)">
                    @foreach($chart['points'] as $point)
                        <span class="chart__hit" role="img" data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}"
                              data-tip data-tip-title="{{ $point['label'] }}" data-tip-rows='@json($point['rows'])' aria-label="{{ $point['aria'] }}">
                            @if($point['previous'] !== null)<span class="chart__dot" data-series="previous" style="inset-block-end: {{ $point['previous'] }}%"></span>@endif
                            @if($point['current'] !== null)<span class="chart__dot" data-series="1" style="inset-block-end: {{ $point['current'] }}%" @if($point['end'] || $point['isolated']) data-pin @endif></span>@endif
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
                            <th scope="col" class="numeric">{{ $chart['series_label'] }}</th>
                            @if($chart['has_previous'])
                                <th scope="col" class="numeric">{{ $chart['previous_label'] }}</th>
                                <th scope="col" class="numeric">{{ __('manager_charts.change') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart['table'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td class="numeric">{{ $row['current'] }}</td>
                                @if($chart['has_previous'])
                                    <td class="numeric">{{ $row['previous'] }}@if($row['previous_label']) <small class="subtle">({{ $row['previous_label'] }})</small>@endif</td>
                                    <td class="numeric"><span dir="ltr">{{ $row['change'] ?? '—' }}</span></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if($chart['totals'])
                        <tfoot>
                            <tr>
                                <th scope="row">{{ __('ui.chart.total') }}</th>
                                <td class="numeric"><strong>{{ $chart['totals']['current'] }}</strong></td>
                                @if($chart['has_previous'])
                                    <td class="numeric"><strong>{{ $chart['totals']['previous'] }}</strong></td>
                                    <td class="numeric"></td>
                                @endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </details>
    </figure>
@endif
