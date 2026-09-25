@props([
    'label',
    'value' => null,
    'max' => 100,
    'format' => 'percent',
    'currency' => null,
    'target' => null,
    'previous' => null,
    'tone' => 'accent',
    'display' => null,
    'caption' => null,
    'size' => '8rem',
    'empty' => null,
])
{{--
    One ratio against its maximum — completion rate, repeat rate, a quota.
    The track is a lighter step of the fill; an optional target tick and
    previous-period dot. A null value (nothing to measure) is the empty state;
    zero is a real 0 %. App\View\Charts\RadialChart; docs/31-MANAGER-CHARTS.md.
--}}
@php($chart = \App\View\Charts\RadialChart::build($label, $value, $max, $format, $currency, $target, $previous, (string) $tone, $display))
@if($chart['empty'])
    <p {{ $attributes->class(['chart__empty']) }}>{{ $empty ?? __('ui.chart.no_data') }}</p>
@else
    <figure {{ $attributes->class(['chart', 'chart--kit', 'chart--radial']) }} role="group" aria-labelledby="{{ $chart['id'] }}-caption">
        <figcaption id="{{ $chart['id'] }}-caption" class="sr-only">{{ $label }}</figcaption>
        <div class="radial" style="--radial-size: {{ $size }}" role="img" aria-label="{{ $chart['summary'] }}">
            <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                <circle class="radial__track" data-series="{{ $chart['series'] }}" cx="50" cy="50" r="{{ \App\View\Charts\RadialChart::RADIUS }}" />
                @if($chart['dash'] > 0)
                    <circle class="radial__value" data-series="{{ $chart['series'] }}" cx="50" cy="50" r="{{ \App\View\Charts\RadialChart::RADIUS }}" pathLength="100" stroke-dasharray="{{ $chart['dash'] }} 100" transform="rotate(-90 50 50)" />
                @endif
                @if($chart['target'])<path class="radial__target" d="{{ $chart['target'] }}" />@endif
                @if($chart['previous'])<circle class="radial__previous" cx="{{ $chart['previous'][0] }}" cy="{{ $chart['previous'][1] }}" r="3" />@endif
            </svg>
            <div class="radial__center" aria-hidden="true">
                <strong dir="ltr">{{ $chart['display'] }}</strong>
                @if($caption)<span>{{ $caption }}</span>@endif
            </div>
        </div>
        @if($chart['target_text'] !== null || $chart['previous_text'] !== null)
            <dl class="radial__meta">
                @if($chart['target_text'] !== null)
                    <div><dt><span class="radial__mark" aria-hidden="true"></span>{{ __('manager_charts.target') }}</dt><dd dir="ltr">{{ $chart['target_text'] }}</dd></div>
                @endif
                @if($chart['previous_text'] !== null)
                    <div><dt><span class="radial__mark radial__mark--previous" aria-hidden="true"></span>{{ __('manager_charts.previous') }}</dt><dd dir="ltr">{{ $chart['previous_text'] }}</dd></div>
                @endif
            </dl>
        @endif
        <details class="chart__data">
            <summary>{{ __('ui.chart.show_data') }}</summary>
            <div class="table-shell">
                <table>
                    <tbody>
                        @foreach($chart['rows'] as $row)
                            <tr><th scope="row">{{ $row['label'] }}</th><td class="numeric">{{ $row['value'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </figure>
@endif
