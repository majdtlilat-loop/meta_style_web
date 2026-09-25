{{--
    One report view as StandardReportView shaped it: the heading and its
    actions, the KPI tiles (each against the comparison period), then the
    sections in reading order — every card a shared x-chart component with
    its own data table, every detail table sortable.
--}}
<section class="std-heading" aria-labelledby="std-report-title">
    <div class="std-heading__title">
        <h2 id="std-report-title">{{ $title }}</h2>
        <p class="std-heading__meta"><x-ui.icon name="clock" size="14" /><time datetime="{{ $result['as_of_iso'] }}">{{ __('manager_reports.std.updated', ['time' => $result['as_of']]) }}</time></p>
    </div>
    <div class="std-heading__actions">
        <button class="button button--ghost button--sm" type="button" wire:click="refresh" wire:loading.attr="data-loading" wire:target="refresh"><x-ui.icon name="refresh" />{{ __('manager_reports.std.actions.refresh') }}</button>
        @if($exportUrl)
            <a class="button button--secondary button--sm" href="{{ $exportUrl }}" download><x-ui.icon name="download" />{{ __('manager_reports.actions.csv') }}</a>
        @endif
        <button class="button button--secondary button--sm" type="button" onclick="window.print()"><x-ui.icon name="print" />{{ __('manager_reports.actions.print') }}</button>
    </div>
</section>

@if($result['other_currencies'] !== [])
    <x-ui.notice tone="info" :message="__('manager_reports.std.other_currencies', ['currency' => $result['currency'], 'amounts' => implode(' · ', $result['other_currencies'])])" />
@endif

@if($result['kpis'] !== [])
    <div class="std-kpis" role="list" aria-label="{{ __('manager_reports.std.sections.kpis') }}">
        @foreach($result['kpis'] as $kpi)
            <x-chart.kpi role="listitem" wire:key="std-kpi-{{ $kpi['key'] }}"
                :label="$kpi['label']" :current="$kpi['current']" :previous="$kpi['previous']"
                :format="$kpi['format']" :currency="$kpi['currency']" :higher-is-better="$kpi['better']"
                :comparison="$result['comparison']" :trend="$kpi['trend']" :help="$kpi['help']" difference />
        @endforeach
    </div>
@endif

@if($result['empty'])
    <x-ui.empty-state class="std-empty" icon="reports" :title="__('manager_reports.std.states.empty')" />
@else
    @foreach($result['sections'] as $section)
        <section class="std-section" aria-labelledby="std-section-{{ $section['key'] }}" wire:key="std-section-{{ $result['code'] }}-{{ $section['key'] }}">
            <h3 class="std-section__title" id="std-section-{{ $section['key'] }}">{{ $section['title'] }}</h3>
            <div class="std-grid">
                @foreach($section['cards'] as $card)
                    @include('livewire.center.reports.standard.card', ['card' => $card])
                @endforeach
            </div>
        </section>
    @endforeach
@endif
