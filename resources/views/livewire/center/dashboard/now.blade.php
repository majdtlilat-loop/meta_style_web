{{-- NOW: no date range applies to what is happening at this moment. --}}
@if($hasNow)
    <section class="dash-now" aria-labelledby="dash-now-title">
        <h2 class="dash-group__title" id="dash-now-title">{{ __('manager_dashboard.sections.now') }}</h2>
        <div class="dash-now__grid">
            @if($now['today'])
                <section class="chart-card chart-card--flush dash-card dash-card--wide" wire:key="dash-now-today" aria-labelledby="dash-today">
                    <header class="chart-card__head">
                        <h3 id="dash-today">{{ __('manager_dashboard.now.today') }}</h3>
                        <span class="chart-card__figure">{{ $now['today']['figure'] }}</span>
                    </header>
                    @if($now['today']['items'] === [])
                        <x-ui.empty-state compact icon="calendar" :title="__('manager_dashboard.now.today_empty')" />
                    @else
                        <ul class="list row-list">
                            @foreach($now['today']['items'] as $row)
                                <li class="row-list__item">
                                    <span class="dash-time" dir="ltr">{{ $row['time'] }}</span>
                                    <div class="row-list__body">
                                        <span class="cell-title">{{ $row['customer'] }}</span>
                                        @if($row['details'] !== '')<span class="cell-sub">{{ $row['details'] }}</span>@endif
                                    </div>
                                    <x-ui.status :value="$row['status']" :label="$row['status_label']" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($now['today']['href'])
                        <footer class="dash-card__foot">
                            @if($now['today']['more'])<span class="subtle">{{ $now['today']['more'] }}</span>@endif
                            <x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$now['today']['href']" wire:navigate>{{ __('manager_dashboard.actions.calendar') }}</x-ui.button>
                        </footer>
                    @endif
                </section>
            @endif

            @if($now['upcoming'] !== null)
                <section class="chart-card chart-card--flush dash-card dash-card--wide" wire:key="dash-now-upcoming" aria-labelledby="dash-upcoming">
                    <header class="chart-card__head">
                        <h3 id="dash-upcoming">{{ __('manager_dashboard.now.upcoming') }}</h3>
                    </header>
                    @if($now['upcoming']['items'] === [])
                        <x-ui.empty-state compact icon="clock" :title="__('manager_dashboard.now.upcoming_empty')" />
                    @else
                        <ul class="list row-list">
                            @foreach($now['upcoming']['items'] as $row)
                                <li class="row-list__item">
                                    <span class="dash-time dash-time--date">{{ $row['time'] }}</span>
                                    <div class="row-list__body">
                                        <span class="cell-title">{{ $row['customer'] }}</span>
                                        @if($row['details'] !== '')<span class="cell-sub">{{ $row['details'] }}</span>@endif
                                    </div>
                                    <x-ui.status :value="$row['status']" :label="$row['status_label']" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if($now['queue'])
                <section class="chart-card dash-card" wire:key="dash-now-queue" aria-labelledby="dash-queue">
                    <header class="chart-card__head">
                        <h3 id="dash-queue">{{ __('manager_dashboard.now.queue') }}</h3>
                        @if($now['queue']['href'])<x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$now['queue']['href']" wire:navigate>{{ __('manager_dashboard.actions.queue') }}</x-ui.button>@endif
                    </header>
                    @if($now['queue']['open'] === 0)
                        <x-ui.empty-state compact icon="queue" :title="__('manager_dashboard.now.queue_empty')" />
                    @else
                        <dl class="dash-states">
                            @foreach($now['queue']['states'] as $state)
                                <div data-state="{{ $state['state'] }}"><dt>{{ $state['label'] }}</dt><dd>{{ $state['value'] }}</dd></div>
                            @endforeach
                        </dl>
                        @if($now['queue']['longest'])<p class="dash-note">{{ $now['queue']['longest'] }}</p>@endif
                    @endif
                </section>
            @endif

            @if($now['sales'])
                <section class="chart-card chart-card--flush dash-card" wire:key="dash-now-sales" aria-labelledby="dash-sales">
                    <header class="chart-card__head">
                        <h3 id="dash-sales">{{ __('manager_dashboard.now.recent_sales') }}</h3>
                        @if($now['sales']['href'])<x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$now['sales']['href']" wire:navigate>{{ __('manager_dashboard.actions.sales') }}</x-ui.button>@endif
                    </header>
                    @if($now['sales']['items'] === [])
                        <x-ui.empty-state compact icon="receipt" :title="__('manager_dashboard.now.sales_empty')" />
                    @else
                        <ul class="list row-list">
                            @foreach($now['sales']['items'] as $sale)
                                <li class="row-list__item">
                                    <span class="row-list__icon" @if($sale['voided']) data-tone="danger" @else data-tone="success" @endif><x-ui.icon name="receipt" /></span>
                                    <div class="row-list__body">
                                        <span class="cell-title">{{ $sale['customer'] }}</span>
                                        <span class="cell-sub"><span dir="ltr">{{ $sale['number'] }}</span> · {{ $sale['when'] }}@if($sale['branch']) · {{ $sale['branch'] }}@endif</span>
                                    </div>
                                    <span class="row-list__meta">
                                        <x-ui.money :minor="$sale['minor']" :currency="$sale['currency']" :class="$sale['voided'] ? 'dash-voided' : ''" />
                                        @if($sale['voided'])<x-ui.status value="voided" :label="__('manager_dashboard.now.voided')" />@endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if($now['customers'])
                <section class="chart-card chart-card--flush dash-card" wire:key="dash-now-customers" aria-labelledby="dash-customers">
                    <header class="chart-card__head">
                        <h3 id="dash-customers">{{ __('manager_dashboard.now.recent_customers') }}</h3>
                        @if($now['customers']['href'])<x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$now['customers']['href']" wire:navigate>{{ __('manager_dashboard.actions.customers') }}</x-ui.button>@endif
                    </header>
                    @if($now['customers']['items'] === [])
                        <x-ui.empty-state compact icon="customers" :title="__('manager_dashboard.now.customers_empty')" />
                    @else
                        <ul class="list row-list">
                            @foreach($now['customers']['items'] as $customer)
                                <li class="row-list__item">
                                    <span class="avatar" aria-hidden="true">{{ $customer['initial'] }}</span>
                                    <div class="row-list__body">
                                        <span class="cell-title">{{ $customer['name'] }}</span>
                                        @if($customer['added'])<span class="cell-sub">{{ $customer['added'] }}</span>@endif
                                    </div>
                                    @if($customer['registered'])<span class="badge" data-tone="primary">{{ __('manager_dashboard.now.registered') }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if($now['team'])
                <section @class(['chart-card dash-card', 'chart-card--flush' => $now['team']['booked'] !== null]) wire:key="dash-now-team" aria-labelledby="dash-team">
                    <header class="chart-card__head">
                        <div class="cluster cluster--tight">
                            <h3 id="dash-team">{{ __('manager_dashboard.now.team') }}</h3>
                            @if($now['team']['note'])<span class="info-tip" role="img" tabindex="0" title="{{ $now['team']['note'] }}" aria-label="{{ $now['team']['note'] }}"><x-ui.icon name="info" size="16" /></span>@endif
                        </div>
                        <span class="chart-card__figure">{{ $now['team']['active'] }}</span>
                    </header>
                    @if($now['team']['booked'] === [])
                        <x-ui.empty-state compact icon="users" :title="__('manager_dashboard.now.team_empty')" />
                    @elseif($now['team']['booked'] !== null)
                        <ul class="list row-list">
                            @foreach($now['team']['booked'] as $person)
                                <li class="row-list__item">
                                    <span class="avatar" aria-hidden="true">{{ $person['initial'] }}</span>
                                    <div class="row-list__body"><span class="cell-title">{{ $person['name'] }}</span></div>
                                    <span class="subtle">{{ $person['bookings'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </div>
    </section>
@endif
