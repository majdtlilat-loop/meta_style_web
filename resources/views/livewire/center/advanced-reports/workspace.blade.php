<div class="adv-workspace" wire:loading.class="is-refreshing">
    @if($focusOptions !== [])
        <div class="adv-focus">
            <label class="adv-focus__field">
                <x-ui.icon name="filter" size="16" />
                <span>{{ __('manager_advanced.focus.label') }}</span>
                <select wire:model.live="focus">
                    <option value="">{{ __('manager_advanced.focus.all') }}</option>
                    @foreach($focusOptions as $kind => $choices)
                        <optgroup label="{{ __('manager_advanced.focus.'.$kind) }}">
                            @foreach($choices as $uuid => $name)
                                <option value="{{ $kind }}:{{ $uuid }}">{{ $name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            @if($focusName)
                <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_advanced.focus.note') }}" aria-label="{{ __('manager_advanced.focus.note') }}"><x-ui.icon name="info" size="14" /></span>
                <button type="button" class="button button--ghost button--sm" wire:click="clearFocus"><x-ui.icon name="close" size="14" />{{ __('manager_advanced.focus.clear') }}</button>
            @endif
        </div>
    @endif

    @if($error)
        <x-ui.notice tone="danger" :message="$error" />
    @elseif($data['empty'])
        <x-ui.empty-state icon="advanced-reports" :title="__('manager_advanced.states.empty_title')" />
    @else
        <section class="adv-section adv-summary" aria-labelledby="adv-summary-title">
            <header class="adv-section__head">
                <h2 id="adv-summary-title"><x-ui.icon name="sparkles" size="18" />{{ __('manager_advanced.sections.summary') }}</h2>
                <span class="adv-section__meta">{{ __('manager_advanced.toolbar.versus', ['period' => $data['comparison']]) }}</span>
            </header>
            @if($data['kpis'] !== [])
                <div class="adv-kpis">
                    @foreach($data['kpis'] as $kpi)
                        <x-chart.kpi :label="$kpi['label']" :current="$kpi['current']" :previous="$kpi['previous']" :format="$kpi['format']" :currency="$kpi['currency']" :higher-is-better="$kpi['higher']" :icon="$kpi['icon']" :trend="$kpi['trend']" wire:key="adv-kpi-{{ $kpi['key'] }}" />
                    @endforeach
                </div>
            @endif
            @if($data['movements']['up'] !== [] || $data['movements']['down'] !== [])
                <div class="adv-movements">
                    @foreach(['up' => 'good', 'down' => 'bad'] as $direction => $tone)
                        @if($data['movements'][$direction] !== [])
                            <div class="adv-movements__group" data-tone="{{ $tone }}">
                                <h3><x-ui.icon :name="$direction === 'up' ? 'arrow-up' : 'arrow-down'" size="16" />{{ __('manager_advanced.movements.'.$direction) }}</h3>
                                <ul>
                                    @foreach($data['movements'][$direction] as $move)
                                        <li>
                                            <span class="adv-movements__label">{{ $move['label'] }}</span>
                                            <span class="adv-movements__values" dir="ltr">{{ $move['values'] }}</span>
                                            <span class="kpi__delta" data-tone="{{ $tone }}" dir="ltr">{{ $move['change'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
            @foreach($data['notes'] as $note)
                <p class="adv-note"><x-ui.icon name="info" size="14" />{{ $note }}</p>
            @endforeach
        </section>

        @if($data['trends'] !== [])
            <section class="adv-section" aria-labelledby="adv-trends-title" x-data="{ metric: @js($data['trends'][0]['key']) }">
                <header class="adv-section__head">
                    <h2 id="adv-trends-title"><x-ui.icon name="dashboard" size="18" />{{ __('manager_advanced.sections.trends') }}</h2>
                    <div class="segmented segmented--sm segmented--scroll adv-metric-switch" role="group" aria-label="{{ __('manager_advanced.trends.metric') }}">
                        @foreach($data['trends'] as $trend)
                            <button type="button" x-on:click="metric = @js($trend['key'])" x-bind:aria-pressed="metric === @js($trend['key']) ? 'true' : 'false'" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $trend['label'] }}</button>
                        @endforeach
                    </div>
                </header>
                @foreach($data['trends'] as $trend)
                    <article class="chart-card adv-trend" x-show="metric === @js($trend['key'])" @unless($loop->first) x-cloak @endunless wire:key="adv-trend-{{ $trend['key'] }}">
                        <header class="chart-card__head">
                            <p class="chart-card__figure"><strong dir="ltr">{{ $trend['total_text'] }}</strong> {{ $trend['label'] }}</p>
                            @if($trend['change'] !== '')<span class="kpi__delta" data-tone="{{ $trend['tone'] }}" dir="ltr">{{ $trend['change'] }}</span>@endif
                        </header>
                        <x-chart.line :label="$trend['label']" :buckets="$trend['buckets']" :series="['label' => $trend['label'], 'values' => $trend['values']]" :previous="$trend['previous']" :format="$trend['format']" :currency="$trend['currency']" area height="14rem" />
                    </article>
                @endforeach
            </section>
        @endif

        @foreach($data['sections'] as $section)
            <section class="adv-section" aria-labelledby="adv-{{ $section['key'] }}-title" wire:key="adv-section-{{ $section['key'] }}">
                <header class="adv-section__head">
                    <h2 id="adv-{{ $section['key'] }}-title"><x-ui.icon :name="$section['icon']" size="18" />{{ $section['title'] }}</h2>
                </header>
                <div class="adv-grid">
                    @foreach($section['cards'] as $card)
                        @include('livewire.center.advanced-reports.card', ['card' => $card])
                    @endforeach
                </div>
                @foreach($section['notes'] as $note)
                    <p class="adv-note"><x-ui.icon name="info" size="14" />{{ $note }}</p>
                @endforeach
            </section>
        @endforeach
    @endif
</div>
