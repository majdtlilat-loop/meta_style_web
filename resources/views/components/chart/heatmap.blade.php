@props([
    'label',
    'rows' => [],
    'columns' => [],
    'values' => [],
    'format' => null,
    'currency' => null,
    'rowHeader' => null,
    'columnHeader' => null,
    'empty' => null,
])
{{--
    A rows × columns matrix (day of week × hour) on the one-hue sequential
    ramp, with a scale legend and a tooltip per cell. Render it only when the
    period has data; an all-zero matrix shows the empty state.
    App\View\Charts\Heatmap; docs/31-MANAGER-CHARTS.md.
--}}
@php($chart = \App\View\Charts\Heatmap::build($label, $rows, $columns, $values, $format, $currency, $rowHeader, $columnHeader))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--heatmap']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption" aria-describedby="{{ $chart['id'] }}-summary">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        <p id="{{ $chart['id'] }}-summary" class="sr-only">{{ $chart['summary'] }}</p>
        <div class="heat" style="--heat-cols: {{ $chart['width'] }}">
            <div class="heat__rows" aria-hidden="true">
                @foreach($chart['rows'] as $rowLabel)<span>{{ $rowLabel }}</span>@endforeach
            </div>
            <div class="heat__grid" role="group" aria-label="{{ $label }}" data-cols="{{ $chart['width'] }}" x-data="{{ \App\View\Charts\Keyboard::ALPINE }}" x-on:keydown="nav($event)">
                @foreach($chart['cells'] as $cell)
                    <span class="heat__cell" data-step="{{ $cell['step'] }}" role="img" data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}"
                          data-tip data-tip-title="{{ $cell['title'] }}" data-tip-rows='@json($cell['rows'])' aria-label="{{ $cell['aria'] }}"></span>
                @endforeach
            </div>
            <div class="heat__cols" aria-hidden="true">
                @foreach($chart['columns'] as $tick)
                    <span @if($tick['thin']) data-thin @endif>@if($tick['show']){{ $tick['label'] }}@endif</span>
                @endforeach
            </div>
        </div>
        <p class="heat__legend" aria-hidden="true">
            <span dir="ltr">{{ $chart['min_label'] }}</span>
            <span class="heat__ramp">
                <i data-step="0" title="{{ $chart['min_label'] }}"></i>
                @foreach($chart['legend'] as $step)<i data-step="{{ $step['step'] }}" title="{{ $step['range'] }}"></i>@endforeach
            </span>
            <span dir="ltr">{{ $chart['max_label'] }}</span>
        </p>
        <details class="chart__data">
            <summary>{{ __('ui.chart.show_data') }}</summary>
            <div class="table-shell">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ $chart['row_header'] }}@if($chart['column_header'] !== '') / {{ $chart['column_header'] }}@endif</th>
                            @foreach($chart['column_labels'] as $columnLabel)<th scope="col" class="numeric">{{ $columnLabel }}</th>@endforeach
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
                </table>
            </div>
        </details>
    </figure>
@endif
