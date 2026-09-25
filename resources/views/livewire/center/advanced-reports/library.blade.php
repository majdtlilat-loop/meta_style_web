<div class="adv-library" wire:loading.class="is-refreshing">
    @if($state === 'forbidden')
        <x-ui.empty-state icon="lock" :title="__('manager_advanced.states.forbidden_title')" />
    @elseif($state === 'none')
        <x-ui.empty-state icon="advanced-reports" :title="__('manager_advanced.states.no_reports_title')" />
    @elseif($state === 'unknown')
        <x-ui.empty-state icon="alert-circle" :title="__('manager_advanced.states.unknown_title')" />
    @elseif($state === 'report_forbidden')
        <x-ui.empty-state icon="lock" :title="__('manager_advanced.states.report_forbidden_title')" />
    @elseif($error)
        <x-ui.notice tone="danger" :message="$error" />
    @elseif($result)
        <section class="adv-section" aria-labelledby="adv-report-title">
            <header class="adv-section__head adv-report-head">
                <div>
                    <h2 id="adv-report-title">{{ $result['title'] }}</h2>
                    @if($result['comparison'])<span class="adv-section__meta"><x-ui.icon name="history" size="14" />{{ __('manager_advanced.toolbar.versus', ['period' => $result['comparison']]) }}</span>@endif
                </div>
                <div class="adv-report-actions">
                    @if($exportUrl)
                        <a class="button button--secondary button--sm" href="{{ $exportUrl }}" download><x-ui.icon name="download" size="16" />{{ __('manager_advanced.toolbar.csv') }}</a>
                    @endif
                    <button type="button" class="button button--secondary button--sm" onclick="window.print()"><x-ui.icon name="print" size="16" />{{ __('manager_advanced.toolbar.print') }}</button>
                </div>
            </header>

            @if($result['kpis'] !== [])
                <div class="adv-kpis">
                    @foreach($result['kpis'] as $kpi)
                        <x-chart.kpi :label="$kpi['label']" :current="$kpi['current']" :previous="$kpi['previous']" :format="$kpi['format']" :currency="$kpi['currency']" :higher-is-better="$kpi['higher']" :value="$kpi['value']" wire:key="adv-lib-kpi-{{ $loop->index }}" />
                    @endforeach
                </div>
            @endif

            @if($result['cards'] !== [])
                <div class="adv-grid">
                    @foreach($result['cards'] as $card)
                        @include('livewire.center.advanced-reports.card', ['card' => $card])
                    @endforeach
                </div>
            @endif

            @if($result['table'] !== null)
                <article class="card card--flush adv-detail">
                    <header class="card__header">
                        <h3>{{ __('manager_advanced.library.detail') }}</h3>
                        <span class="badge">{{ trans_choice('manager_advanced.library.rows', $result['row_count'], ['count' => number_format($result['row_count'])]) }}</span>
                    </header>
                    @include('livewire.center.advanced-reports.table', ['table' => $result['table']])
                </article>
            @elseif($result['kpis'] === [] && $result['cards'] === [])
                <x-ui.empty-state icon="advanced-reports" :title="__('manager_advanced.states.empty_title')" compact />
            @endif

            @if($result['glossary'] !== [] || $result['unavailable'] !== [])
                <details class="adv-about">
                    <summary><x-ui.icon name="info" size="16" />{{ __('manager_advanced.library.about') }} <small>{{ __('manager_advanced.library.as_of', ['time' => $result['as_of']]) }}</small></summary>
                    <dl>
                        @foreach($result['glossary'] as $note)
                            <div><dt>{{ $note['term'] }}</dt><dd>{{ $note['text'] }}</dd></div>
                        @endforeach
                        @foreach($result['unavailable'] as $note)
                            <div class="adv-about__unavailable"><dt>{{ $note['term'] }}</dt><dd>{{ $note['text'] }}</dd></div>
                        @endforeach
                    </dl>
                </details>
            @endif
        </section>
    @endif
</div>
