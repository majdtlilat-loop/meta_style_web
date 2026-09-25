@props([
    'label',
    'value',
    'delta' => null,
    'comparison' => null,
    'hint' => null,
    'icon' => null,
    'href' => null,
    'trend' => [],
])
{{--
    A KPI: the number, how it moved against the comparable period, and an
    optional trend. `delta` is an App\View\Delta — its tone already knows
    whether this metric going up is good or bad.
--}}
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['kpi', 'kpi--link' => (bool) $href]) }}>
    <div class="kpi__head">
        <p class="kpi__label">{{ $label }}</p>
        @if($icon)<span class="kpi__icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span>@endif
    </div>
    <p class="kpi__value">{{ $value }}</p>
    <div class="kpi__foot">
        @if($delta instanceof \App\View\Delta)
            <span class="kpi__delta" data-tone="{{ $delta->tone }}">
                @if($delta->direction !== 'flat')<x-ui.icon :name="$delta->direction === 'up' ? 'arrow-up' : 'arrow-down'" size="12" />@endif
                <span dir="ltr">{{ $delta->text }}</span>
            </span>
            @if($comparison)<span class="kpi__compare">{{ $comparison }}</span>@endif
        @elseif($hint)
            <span class="kpi__compare">{{ $hint }}</span>
        @endif
        @if($trend !== [])<x-chart.sparkline :values="$trend" />@endif
    </div>
</{{ $tag }}>
