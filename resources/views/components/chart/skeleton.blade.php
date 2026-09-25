@props([
    'type' => 'columns',
    'height' => '12rem',
    'label' => null,
])
{{--
    A chart-shaped placeholder for a FIRST load (a lazy Livewire placeholder,
    or `wire:loading` before any figures exist). A refetch keeps the previous
    render dimmed instead (`wire:loading.class="is-refreshing"`), so the page
    never flashes. The shimmer stops under prefers-reduced-motion.

    type: columns | line | bars | donut | heatmap | kpi
--}}
<div {{ $attributes->class(['chart-skeleton', 'chart-skeleton--'.$type]) }} role="status" style="--chart-height: {{ $height }}">
    <span class="sr-only">{{ $label ?? __('manager_charts.loading') }}</span>
    @switch($type)
        @case('line')
            <div class="chart-skeleton__plot" aria-hidden="true"><span class="skeleton chart-skeleton__area"></span></div>
            <div class="chart-skeleton__axis" aria-hidden="true">@for($i = 0; $i < 5; $i++)<span class="skeleton"></span>@endfor</div>
            @break
        @case('bars')
            <div class="chart-skeleton__rows" aria-hidden="true">
                @foreach([92, 74, 58, 41, 27] as $width)
                    <div class="chart-skeleton__row"><span class="skeleton"></span><span class="skeleton" style="inline-size: {{ $width }}%"></span></div>
                @endforeach
            </div>
            @break
        @case('donut')
            <div class="chart-skeleton__donut" aria-hidden="true">
                <span class="skeleton chart-skeleton__ring"></span>
                <div class="chart-skeleton__lines">
                    @foreach([80, 64, 72, 50] as $width)<span class="skeleton" style="inline-size: {{ $width }}%"></span>@endforeach
                </div>
            </div>
            @break
        @case('heatmap')
            <div class="chart-skeleton__cells" aria-hidden="true">@for($i = 0; $i < 84; $i++)<span class="skeleton"></span>@endfor</div>
            @break
        @case('kpi')
            <div class="chart-skeleton__kpi" aria-hidden="true"><span class="skeleton"></span><span class="skeleton"></span><span class="skeleton"></span></div>
            @break
        @default
            <div class="chart-skeleton__legend" aria-hidden="true"><span class="skeleton"></span><span class="skeleton"></span></div>
            <div class="chart-skeleton__plot" aria-hidden="true">
                @foreach([38, 62, 47, 80, 55, 70, 43, 88, 60, 74, 51, 84] as $bar)<span class="skeleton" style="block-size: {{ $bar }}%"></span>@endforeach
            </div>
            <div class="chart-skeleton__axis" aria-hidden="true">@for($i = 0; $i < 5; $i++)<span class="skeleton"></span>@endfor</div>
    @endswitch
</div>
