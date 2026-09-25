{{--
    One period KPI. The shared .kpi styling, plus a hint line that stays
    visible beside the comparison (another currency is disclosed there).
--}}
<{{ $kpi['href'] ? 'a' : 'div' }} @if($kpi['href']) href="{{ $kpi['href'] }}" wire:navigate @endif @class(['kpi', 'kpi--link' => (bool) $kpi['href']])>
    <div class="kpi__head">
        <p class="kpi__label">{{ $kpi['label'] }}</p>
        <span class="kpi__icon" aria-hidden="true"><x-ui.icon :name="$kpi['icon']" /></span>
    </div>
    <p class="kpi__value">{{ $kpi['value'] }}</p>
    <div class="kpi__foot">
        @if($kpi['delta'])
            <span class="kpi__delta" data-tone="{{ $kpi['delta']->tone }}">
                @if($kpi['delta']->direction !== 'flat')<x-ui.icon :name="$kpi['delta']->direction === 'up' ? 'arrow-up' : 'arrow-down'" size="12" />@endif
                <span dir="ltr">{{ $kpi['delta']->text }}</span>
            </span>
            <span class="kpi__compare">{{ $kpi['comparison'] }}</span>
        @endif
        @if($kpi['trend'] !== [])<x-chart.sparkline :values="$kpi['trend']" />@endif
    </div>
    @if($kpi['hint'])<p class="kpi__hint">{{ $kpi['hint'] }}</p>@endif
</{{ $kpi['href'] ? 'a' : 'div' }}>
