@props([
    'label',
    'current' => null,
    'previous' => null,
    'format' => null,
    'currency' => null,
    'higherIsBetter' => true,
    'value' => null,
    'comparison' => null,
    'hint' => null,
    'icon' => null,
    'href' => null,
    'trend' => [],
    'help' => null,
    'difference' => false,
])
{{--
    A KPI with a SEMANTIC delta: the metric declares whether higher is better
    (true), worse (false — cancellations, waits) or neither (null), and the
    tone follows that, never the arrow. The percentage appears only when the
    previous value is non-zero; a percent metric moves in points.
    `help` (optional) adds an info tooltip with the metric's definition
    (not on a linked tile: no focus target inside a link).
    `difference` (optional, default off) also prints the absolute difference
    beside a percentage delta ("+25%  +5,000 IQD").
    App\View\Charts\KpiFigure; docs/31-MANAGER-CHARTS.md.
--}}
@php($kpi = \App\View\Charts\KpiFigure::build($current, $previous, $format, $currency, $higherIsBetter, $value, $comparison))
<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['kpi', 'chart-kpi', 'kpi--link' => (bool) $href]) }}>
    <div class="kpi__head">
        <p class="kpi__label">{{ $label }}@if($help && ! $href)<span class="info-tip" role="img" tabindex="0" title="{{ $help }}" aria-label="{{ $help }}"><x-ui.icon name="info" size="14" /></span>@endif</p>
        @if($icon)<span class="kpi__icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span>@endif
    </div>
    <p class="kpi__value">{{ $kpi['value'] }}</p>
    <div class="kpi__foot">
        @if($kpi['delta'])
            <span class="kpi__delta" data-tone="{{ $kpi['delta']['tone'] }}">
                @if($kpi['delta']['direction'] !== 'flat')<x-ui.icon :name="$kpi['delta']['direction'] === 'up' ? 'arrow-up' : 'arrow-down'" size="12" />@endif
                <span dir="ltr">{{ $kpi['delta']['text'] }}</span>
                @if($kpi['delta']['sr'] !== '')<span class="sr-only">({{ $kpi['delta']['sr'] }})</span>@endif
            </span>
            @if($difference && $kpi['difference'] !== null)<span class="kpi__compare kpi__difference" dir="ltr">{{ $kpi['difference'] }}</span>@endif
        @endif
        @if($kpi['compare'])
            <span class="kpi__compare">{{ $kpi['compare'] }}</span>
        @elseif($hint)
            <span class="kpi__compare">{{ $hint }}</span>
        @endif
        @if($trend !== [])<x-chart.sparkline :values="$trend" />@endif
    </div>
</{{ $href ? 'a' : 'div' }}>
