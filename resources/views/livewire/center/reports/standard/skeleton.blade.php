{{-- Shown only while another report view loads (a tab was chosen): the shape of what is coming, never a blank page. --}}
<div class="std-switching" x-show="switching" x-cloak role="status">
    <span class="sr-only">{{ __('manager_reports.std.states.loading') }}</span>
    <div class="std-kpis std-switching__kpis" aria-hidden="true">
        @for($i = 0; $i < 4; $i++)
            <x-chart.skeleton type="kpi" />
        @endfor
    </div>
    <div class="std-grid" aria-hidden="true">
        <div class="std-card std-card--wide chart-card"><x-chart.skeleton type="line" /></div>
        <div class="std-card chart-card"><x-chart.skeleton type="donut" /></div>
        <div class="std-card chart-card"><x-chart.skeleton type="bars" /></div>
    </div>
</div>
