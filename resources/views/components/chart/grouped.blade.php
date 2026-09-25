@props([
    'label',
    'groups' => [],
    'series' => [],
    'format' => null,
    'currency' => null,
    'height' => '12rem',
    'empty' => null,
])
{{--
    Grouped columns: 2–4 entities side by side per group (branch vs branch,
    employee vs employee), one shared unit on one y-axis. Past four entities,
    three stay and the rest fold into "Other".
    App\View\Charts\GroupedChart; docs/31-MANAGER-CHARTS.md.
--}}
@php($chart = \App\View\Charts\GroupedChart::build($label, $groups, $series, $format, $currency))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--grouped']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption">
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
                            @foreach($column['bars'] as $bar)
                                <span class="chart__bar" data-series="{{ $bar['series'] }}" style="block-size: {{ $bar['height'] }}%" @if($bar['empty']) data-empty @endif></span>
                            @endforeach
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
                            <th scope="col">{{ $label }}</th>
                            @foreach($chart['legend'] as $entry)<th scope="col" class="numeric">{{ $entry['label'] }}</th>@endforeach
                            @foreach($chart['members'] as $member)<th scope="col" class="numeric">{{ $member['label'] }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart['table'] as $g => $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach($row['values'] as $value)<td class="numeric">{{ $value }}</td>@endforeach
                                @foreach($chart['members'] as $member)<td class="numeric">{{ $member['values'][$g] }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </figure>
@endif
