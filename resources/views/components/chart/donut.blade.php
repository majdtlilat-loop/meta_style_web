@props([
    'label',
    'items' => [],
    'mode' => 'categorical',
    'format' => null,
    'currency' => null,
    'sort' => null,
    'keep' => 7,
    'total' => null,
    'centerLabel' => null,
    'size' => '10rem',
    'empty' => null,
])
{{--
    Part-to-whole at a glance: at most seven named slices plus "Other", 2px
    surface gaps, the total in the centre, a legend with value and share.
    `mode="status"` wears the reserved status palette. Geometry and folding:
    App\View\Charts\DonutChart. docs/31-MANAGER-CHARTS.md has the API.
--}}
@php($chart = \App\View\Charts\DonutChart::build($label, $items, (string) $mode, $format, $currency, $sort, (int) $keep, $total, $centerLabel))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--donut']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        <div class="donut">
            <div class="donut__figure" style="--donut-size: {{ $size }}" role="img" aria-label="{{ $chart['summary'] }}">
                @foreach($chart['slices'] as $slice)
                    @if($slice['d'])
                        <span class="donut__layer" data-tip data-tip-title="{{ $label }}" data-tip-rows='@json($slice['rows'])'>
                            <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"><path class="donut__slice" data-series="{{ $slice['series'] }}" data-slice="{{ $slice['index'] }}" d="{{ $slice['d'] }}" /></svg>
                        </span>
                    @endif
                @endforeach
                <div class="donut__center" aria-hidden="true">
                    <strong dir="ltr">{{ $chart['total'] }}</strong>
                    <span>{{ $chart['center_label'] }}</span>
                </div>
            </div>
            <ul class="donut__legend" aria-label="{{ $label }}" x-data="{{ \App\View\Charts\Keyboard::ALPINE }}" x-on:keydown="nav($event)">
                @foreach($chart['slices'] as $slice)
                    <li data-slice="{{ $slice['index'] }}" data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}"
                        data-tip data-tip-title="{{ $label }}" data-tip-rows='@json($slice['rows'])'>
                        <span class="chart__key" data-series="{{ $slice['series'] }}" aria-hidden="true"></span>
                        <span class="donut__name">{{ $slice['label'] }}</span>
                        <span class="donut__value" dir="ltr">{{ $slice['value'] }}</span>
                        <span class="donut__share" dir="ltr">{{ $slice['share'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
        <details class="chart__data">
            <summary>{{ __('ui.chart.show_data') }}</summary>
            <div class="table-shell">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ $label }}</th>
                            <th scope="col" class="numeric">{{ __('manager_charts.value') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_charts.share') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart['slices'] as $slice)
                            <tr>
                                <th scope="row">{{ $slice['label'] }}</th>
                                <td class="numeric">{{ $slice['value'] }}</td>
                                <td class="numeric">{{ $slice['share'] }}</td>
                            </tr>
                            @foreach($slice['members'] as $member)
                                <tr>
                                    <td>&ensp;{{ $member['label'] }}</td>
                                    <td class="numeric">{{ $member['value'] }}</td>
                                    <td class="numeric">{{ $member['share'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">{{ __('ui.chart.total') }}</th>
                            <td class="numeric"><strong>{{ $chart['total_full'] }}</strong></td>
                            <td class="numeric">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </details>
    </figure>
@endif
