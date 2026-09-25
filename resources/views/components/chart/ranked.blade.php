@props([
    'label',
    'items' => [],
    'format' => null,
    'currency' => null,
    'limit' => 7,
    'share' => false,
    'sort' => true,
    'higherIsBetter' => true,
    'previousLabel' => null,
    'empty' => null,
])
{{--
    Ranked horizontal bars: largest first, the value at the bar's end, an
    optional previous-period tick, the tail past `limit` folded into "Other".
    App\View\Charts\RankedChart; docs/31-MANAGER-CHARTS.md.
--}}
@php($chart = \App\View\Charts\RankedChart::build($label, $items, $format, $currency, (int) $limit, (bool) $share, (bool) $sort, $higherIsBetter))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--ranked']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption" aria-describedby="{{ $chart['id'] }}-summary">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        <p id="{{ $chart['id'] }}-summary" class="sr-only">{{ $chart['summary'] }}</p>
        @if($chart['has_previous'])
            <ul class="ranked__legend" aria-hidden="true">
                <li><span class="chart__key" data-series="1"></span>{{ $label }}</li>
                <li><span class="ranked__prev"></span>{{ $previousLabel ?? __('manager_charts.previous') }}</li>
            </ul>
        @endif
        <ol class="ranked" aria-label="{{ $label }}" x-data="{{ \App\View\Charts\Keyboard::ALPINE }}" x-on:keydown="nav($event)">
            @foreach($chart['rows'] as $row)
                <li class="ranked__row" data-tip data-tip-title="{{ $row['label'] }}" data-tip-rows='@json($row['rows'])'
                    @unless($row['href']) data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}" @endunless>
                    <span class="ranked__label">
                        @if($row['href'])<a href="{{ $row['href'] }}" wire:navigate data-chart-target tabindex="{{ $loop->first ? '0' : '-1' }}">{{ $row['label'] }}</a>@else{{ $row['label'] }}@endif
                        @if($row['meta'])<small>{{ $row['meta'] }}</small>@endif
                    </span>
                    <span class="ranked__track" aria-hidden="true">
                        <span class="ranked__bar" data-series="{{ $row['series'] }}" style="inline-size: {{ $row['width'] }}%"></span>
                        @if($row['previous_pos'] !== null)<span class="ranked__prev" style="inset-inline-start: {{ $row['previous_pos'] }}%"></span>@endif
                    </span>
                    <span class="ranked__value">
                        <strong dir="ltr">{{ $row['value'] }}</strong>
                        @if($share)<small dir="ltr">{{ $row['share'] }}</small>@elseif($row['change'])<small dir="ltr">{{ $row['change'] }}</small>@endif
                    </span>
                </li>
            @endforeach
        </ol>
        <details class="chart__data">
            <summary>{{ __('ui.chart.show_data') }}</summary>
            <div class="table-shell">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ $label }}</th>
                            <th scope="col" class="numeric">{{ __('manager_charts.value') }}</th>
                            @if($chart['has_previous'])<th scope="col" class="numeric">{{ $previousLabel ?? __('manager_charts.previous') }}</th>@endif
                            <th scope="col" class="numeric">{{ __('manager_charts.share') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td class="numeric">{{ $row['value'] }}</td>
                                @if($chart['has_previous'])<td class="numeric">{{ $row['previous'] ?? '—' }}</td>@endif
                                <td class="numeric">{{ $row['share'] }}</td>
                            </tr>
                            @foreach($row['members'] as $member)
                                <tr>
                                    <td>&ensp;{{ $member['label'] }}</td>
                                    <td class="numeric">{{ $member['value'] }}</td>
                                    @if($chart['has_previous'])<td class="numeric">{{ $member['previous'] ?? '—' }}</td>@endif
                                    <td class="numeric">{{ $member['share'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    @if($chart['total'])
                        <tfoot>
                            <tr>
                                <th scope="row">{{ __('ui.chart.total') }}</th>
                                <td class="numeric"><strong>{{ $chart['total'] }}</strong></td>
                                @if($chart['has_previous'])<td class="numeric"></td>@endif
                                <td class="numeric">100%</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </details>
    </figure>
@endif
