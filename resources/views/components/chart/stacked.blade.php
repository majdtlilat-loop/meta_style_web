@props([
    'label',
    'buckets' => [],
    'series' => [],
    'mode' => 'categorical',
    'format' => null,
    'currency' => null,
    'height' => '12rem',
    'empty' => null,
])
{{--
    Stacked columns over time: parts per bucket with a 2px surface gap, a
    legend, one tooltip per bucket with every part and the total.
    `mode="status"` wears the reserved status palette (bookings by status).
    App\View\Charts\StackedChart; docs/31-MANAGER-CHARTS.md.
--}}
@php($chart = \App\View\Charts\StackedChart::build($label, $buckets, $series, (string) $mode, $format, $currency))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--stacked']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        <ul class="chart__legend" aria-hidden="true">
            @foreach($chart['legend'] as $entry)
                <li><span class="chart__key" data-series="{{ $entry['series'] }}"></span>{{ $entry['label'] }}</li>
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
                <div class="chart__columns chart__marks" role="img" aria-label="{{ $chart['summary'] }}">
                    @foreach($chart['columns'] as $column)
                        <div class="chart__bucket">
                            @if($column['segments'] !== [])
                                <div class="chart__stack" style="block-size: {{ $column['height'] }}%">
                                    @foreach($column['segments'] as $segment)
                                        <span class="chart__seg" data-series="{{ $segment['series'] }}" style="flex-grow: {{ $segment['grow'] }}"></span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="chart__hits chart__hits--columns" role="group" aria-label="{{ $label }}" x-data="{{ \App\View\Charts\Keyboard::ALPINE }}" x-on:keydown="nav($event)">
                    @foreach($chart['columns'] as $column)
                        <span class="chart__hit" role="img" data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}"
                              data-tip data-tip-title="{{ $column['label'] }}" data-tip-rows='@json($column['rows'])' aria-label="{{ $column['aria'] }}"></span>
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
                            <th scope="col" class="numeric">{{ __('ui.chart.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart['table'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach($row['values'] as $value)<td class="numeric">{{ $value }}</td>@endforeach
                                <td class="numeric"><strong>{{ $row['total'] }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">{{ __('ui.chart.total') }}</th>
                            @foreach($chart['column_totals'] as $value)<td class="numeric"><strong>{{ $value }}</strong></td>@endforeach
                            <td class="numeric"><strong>{{ $chart['grand_total'] }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if($chart['members'] !== [])
                <div class="table-shell">
                    <table>
                        <caption class="subtle">{{ __('manager_charts.other') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('ui.chart.period') }}</th>
                                @foreach($chart['members'] as $member)<th scope="col" class="numeric">{{ $member['label'] }}</th>@endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($chart['table'] as $b => $row)
                                <tr>
                                    <th scope="row">{{ $row['label'] }}</th>
                                    @foreach($chart['members'] as $member)<td class="numeric">{{ $member['values'][$b] }}</td>@endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </details>
    </figure>
@endif
