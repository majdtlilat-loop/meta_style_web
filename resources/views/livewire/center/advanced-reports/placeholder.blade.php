{{-- First load of a lazy section: chart-shaped skeletons, announced once. --}}
<div class="adv-skeleton" aria-busy="true">
    @if($kind === 'workspace')
        <div class="adv-kpis">
            @for($i = 0; $i < 4; $i++)
                <x-chart.skeleton type="kpi" height="6rem" :label="$i === 0 ? __('manager_advanced.states.loading') : ''" />
            @endfor
        </div>
        <div class="adv-grid">
            <div class="chart-card adv-span-two-thirds"><x-chart.skeleton type="line" /></div>
            <div class="chart-card adv-span-third"><x-chart.skeleton type="donut" /></div>
        </div>
    @elseif($kind === 'compare')
        <div class="adv-grid">
            <div class="chart-card adv-span-full"><x-chart.skeleton type="bars" :label="__('manager_advanced.states.loading')" /></div>
            <div class="chart-card adv-span-half"><x-chart.skeleton type="columns" /></div>
            <div class="chart-card adv-span-half"><x-chart.skeleton type="line" /></div>
        </div>
    @else
        <div class="adv-kpis">
            @for($i = 0; $i < 3; $i++)
                <x-chart.skeleton type="kpi" height="6rem" :label="$i === 0 ? __('manager_advanced.states.loading') : ''" />
            @endfor
        </div>
        <div class="adv-grid">
            <div class="chart-card adv-span-full"><x-chart.skeleton type="bars" /></div>
        </div>
    @endif
</div>
