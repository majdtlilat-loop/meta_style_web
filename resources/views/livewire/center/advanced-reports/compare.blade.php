<div class="adv-compare-view">
    @if($modes !== [])
        <div class="adv-compare-head">
            <div class="segmented segmented--scroll adv-modes" role="group" aria-label="{{ __('manager_advanced.compare.mode_label') }}">
                @foreach($modes as $key)
                    <button type="button" wire:click="setMode('{{ $key }}')" aria-pressed="{{ $mode === $key ? 'true' : 'false' }}">{{ __('manager_advanced.compare.modes.'.$key) }}</button>
                @endforeach
            </div>
            @if($mode !== 'periods' && $options !== [])
                <fieldset class="adv-picker">
                    <legend>{{ __('manager_advanced.compare.pick', ['max' => $max]) }}</legend>
                    <div class="adv-picker__chips">
                        @foreach($options as $uuid => $name)
                            <button type="button" class="adv-chip" wire:click="toggle('{{ $uuid }}')" wire:key="adv-chip-{{ $uuid }}" aria-pressed="{{ in_array($uuid, $selected, true) ? 'true' : 'false' }}" @disabled(! in_array($uuid, $selected, true) && count($selected) >= $max)>
                                @if(in_array($uuid, $selected, true))<span class="chart__key" data-series="{{ array_search($uuid, $selected, true) + 1 }}" aria-hidden="true"></span>@endif
                                {{ $name }}
                            </button>
                        @endforeach
                    </div>
                </fieldset>
            @endif
        </div>
    @endif

    <div class="adv-compare-body" wire:loading.class="is-refreshing" wire:target="setMode,toggle">
        @if($error)
            <x-ui.notice tone="danger" :message="$error" />
        @elseif($mode !== 'periods' && $options === [])
            <x-ui.empty-state icon="layers" :title="__('manager_advanced.compare.no_options')" compact />
        @elseif($mode !== 'periods' && ! $data['ready'])
            <x-ui.empty-state icon="layers" :title="__('manager_advanced.compare.pick_two')" compact />
        @elseif($mode === 'periods' && ! $data['ready'])
            <x-ui.empty-state icon="advanced-reports" :title="__('manager_advanced.states.empty_title')" />
        @elseif($mode === 'periods')
            <section class="adv-section" aria-labelledby="adv-periods-title">
                <header class="adv-section__head">
                    <h2 id="adv-periods-title"><x-ui.icon name="history" size="18" />{{ __('manager_advanced.compare.modes.periods') }}</h2>
                    <span class="adv-section__meta">{{ $data['period'] }} · {{ __('manager_advanced.toolbar.versus', ['period' => $data['comparison']]) }}</span>
                </header>
                <div class="table-shell table-shell--stack adv-table">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('manager_advanced.columns.metric') }}</th>
                                <th scope="col" class="numeric">{{ __('manager_advanced.period.current_series') }}</th>
                                <th scope="col" class="numeric">{{ __('manager_advanced.period.comparison_series') }}</th>
                                <th scope="col" class="numeric">{{ __('manager_advanced.columns.difference') }}</th>
                                <th scope="col" class="numeric">{{ __('manager_advanced.columns.change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['rows'] as $row)
                                <tr>
                                    <td data-primary><span class="cell-title">{{ $row['label'] }}</span><span class="cell-sub">{{ $row['section'] }}</span></td>
                                    <td class="numeric" data-label="{{ __('manager_advanced.period.current_series') }}"><strong dir="ltr">{{ $row['current'] }}</strong></td>
                                    <td class="numeric" data-label="{{ __('manager_advanced.period.comparison_series') }}"><span dir="ltr">{{ $row['previous'] }}</span></td>
                                    <td class="numeric" data-label="{{ __('manager_advanced.columns.difference') }}"><span dir="ltr">{{ $row['difference'] }}</span></td>
                                    <td class="numeric" data-label="{{ __('manager_advanced.columns.change') }}">
                                        @if($row['change'] !== '—')<span class="kpi__delta" data-tone="{{ $row['tone'] }}" dir="ltr">{{ $row['change'] }}</span>@else—@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @foreach($data['notes'] as $note)
                    <p class="adv-note"><x-ui.icon name="info" size="14" />{{ $note }}</p>
                @endforeach
            </section>
            @if($data['cards'] !== [])
                <div class="adv-grid">
                    @foreach($data['cards'] as $card)
                        @include('livewire.center.advanced-reports.card', ['card' => $card])
                    @endforeach
                </div>
            @endif
        @else
            <section class="adv-section" aria-labelledby="adv-entities-title">
                <header class="adv-section__head">
                    <h2 id="adv-entities-title"><x-ui.icon name="layers" size="18" />{{ __('manager_advanced.compare.modes.'.$mode) }}</h2>
                    <span class="adv-section__meta">{{ $data['period'] }}</span>
                </header>
                <div class="table-shell adv-table adv-table--compare">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('manager_advanced.columns.metric') }}</th>
                                @foreach($data['entities'] as $index => $entity)
                                    <th scope="col" class="numeric"><span class="chart__key" data-series="{{ $index + 1 }}" aria-hidden="true"></span> {{ $entity }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['table'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['label'] }}</th>
                                    @foreach($row['cells'] as $cell)
                                        <td class="numeric">
                                            <strong dir="ltr">{{ $cell['value'] }}</strong>
                                            @if($cell['delta'])<span class="kpi__delta adv-delta" data-tone="{{ $cell['tone'] }}" dir="ltr">{{ $cell['delta'] }}</span>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="adv-note"><x-ui.icon name="info" size="14" />{{ __('manager_advanced.compare.baseline', ['name' => $data['entities'][0]]) }}</p>
                @foreach($data['notes'] as $note)
                    <p class="adv-note"><x-ui.icon name="info" size="14" />{{ $note }}</p>
                @endforeach
            </section>

            @if($data['trends'] !== [])
                <section class="adv-section" aria-labelledby="adv-overlay-title" x-data="{ metric: @js($data['trends'][0]['key']) }">
                    <header class="adv-section__head">
                        <h2 id="adv-overlay-title"><x-ui.icon name="dashboard" size="18" />{{ __('manager_advanced.compare.trend') }}</h2>
                        <div class="segmented segmented--sm segmented--scroll adv-metric-switch" role="group" aria-label="{{ __('manager_advanced.trends.metric') }}">
                            @foreach($data['trends'] as $trend)
                                <button type="button" x-on:click="metric = @js($trend['key'])" x-bind:aria-pressed="metric === @js($trend['key']) ? 'true' : 'false'" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $trend['label'] }}</button>
                            @endforeach
                        </div>
                    </header>
                    @foreach($data['trends'] as $trend)
                        <article class="chart-card adv-trend" x-show="metric === @js($trend['key'])" @unless($loop->first) x-cloak @endunless wire:key="adv-overlay-{{ $trend['key'] }}">
                            <x-chart.multiline :label="$trend['label']" :buckets="$trend['buckets']" :series="$trend['series']" :format="$trend['format']" :currency="$trend['currency']" height="14rem" />
                        </article>
                    @endforeach
                </section>
            @endif

            @if($data['cards'] !== [])
                <div class="adv-grid">
                    @foreach($data['cards'] as $card)
                        @include('livewire.center.advanced-reports.card', ['card' => $card])
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
