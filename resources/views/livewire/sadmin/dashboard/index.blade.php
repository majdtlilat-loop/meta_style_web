@php
    use App\View\Delta;
    use App\View\Label;

    $can = static fn (string $permission): bool => (bool) auth('platform')->user()?->hasPermission($permission);
    $link = static fn (string $permission, string $route): ?string => $can($permission) && Route::has($route) ? route($route) : null;
    $compare = __('ui.range.'.$period->comparisonKey());

    $centers = $data['centers'];
    $subscriptions = $data['subscriptions'];
    $billing = $data['billing'];
    $support = $data['support'];
    $lead = $billing['currencies'][0] ?? null;
    $buckets = $data['buckets'];

    $statusItems = static function (array $counts, string $group, array $order = []) : array {
        $keys = $order !== [] ? array_values(array_unique(array_merge($order, array_keys($counts)))) : array_keys($counts);
        $items = [];
        foreach ($keys as $key) {
            if (($counts[$key] ?? 0) > 0) {
                $items[] = ['label' => Label::for($group, (string) $key), 'value' => $counts[$key]];
            }
        }
        return $items;
    };
@endphp
<div class="dash">
    <x-ui.page-header :title="__('sadmin_dashboard.title')">
        <x-slot:actions>
            @if($url = $link('platform.operations.view', 'superadmin.operations.index'))
                <x-ui.button variant="secondary" icon="operations" :href="$url" wire:navigate>{{ __('sadmin_dashboard.actions.operations') }}</x-ui.button>
            @endif
            @if($url = $link('platform.center.view', 'superadmin.centers.index'))
                <x-ui.button icon="centers" :href="$url" wire:navigate>{{ __('sadmin_dashboard.actions.centers') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.date-range :range="$this->range" :current="$period" :open="$customOpen" />

    <div class="dash" wire:loading.class="is-refreshing" wire:target="setRange,applyCustomRange">
        {{-- Provisioning problems exist only when something failed; otherwise nothing is shown. --}}
        @if($data['issues'] !== [])
            <div class="notice notice--danger" role="status">
                <x-ui.icon name="alert-triangle" />
                <div class="grow">
                    <strong>{{ trans_choice('sadmin_dashboard.issues.title', count($data['issues']), ['count' => count($data['issues'])]) }}</strong>
                    <p>
                        @foreach($data['issues'] as $issue)
                            <a href="{{ Route::has('superadmin.centers.show') ? route('superadmin.centers.show', $issue->id) : '#' }}" wire:navigate>{{ $issue->name }}</a>@if(! $loop->last) · @endif
                        @endforeach
                    </p>
                </div>
            </div>
        @endif

        {{-- Key figures --}}
        <section class="kpi-grid" aria-label="{{ __('sadmin_dashboard.kpi.label') }}">
            <x-ui.kpi icon="centers" :label="__('sadmin_dashboard.kpi.total_centers')" :value="number_format($centers['total'])"
                :delta="Delta::between($centers['total'], $centers['total_previous'])" :comparison="$compare"
                :trend="$centers['total_series']" :href="$link('platform.center.view', 'superadmin.centers.index')" />
            <x-ui.kpi icon="user-plus" :label="__('sadmin_dashboard.kpi.new_centers')" :value="number_format($centers['new'])"
                :delta="Delta::between($centers['new'], $centers['new_previous'])" :comparison="$compare" :trend="$centers['new_series']" />
            <x-ui.kpi icon="subscriptions" :label="__('sadmin_dashboard.kpi.live_subscriptions')" :value="number_format($subscriptions['live'])"
                :hint="__('sadmin_dashboard.kpi.live_hint', ['trialing' => number_format($subscriptions['by_status']['trialing'] ?? 0), 'past_due' => number_format($subscriptions['by_status']['past_due'] ?? 0)])"
                :href="$link('platform.subscription.manage', 'superadmin.subscriptions.index')" />
            @if($lead)
                <x-ui.kpi icon="wallet" :label="__('sadmin_dashboard.kpi.collected')" :value="\App\Kernel\Money\Currency::tryFrom($lead['currency']) ? \App\Kernel\Money\Money::fromMinor($lead['collected'], \App\Kernel\Money\Currency::from($lead['currency']))->formatted(app()->getLocale()) : number_format($lead['collected']).' '.$lead['currency']"
                    :delta="Delta::between($lead['collected'], $lead['collected_previous'], 'up', money: true)" :comparison="$compare"
                    :trend="$lead['collected_series']" :href="$link('platform.billing.manage', 'superadmin.billing.index')" />
                <x-ui.kpi icon="billing" :label="__('sadmin_dashboard.kpi.outstanding')" :value="\App\Kernel\Money\Currency::tryFrom($lead['currency']) ? \App\Kernel\Money\Money::fromMinor($lead['outstanding'], \App\Kernel\Money\Currency::from($lead['currency']))->formatted(app()->getLocale()) : number_format($lead['outstanding']).' '.$lead['currency']"
                    :hint="trans_choice('sadmin_dashboard.kpi.overdue', $billing['overdue'], ['count' => $billing['overdue']])"
                    :href="$link('platform.billing.manage', 'superadmin.billing.index')" />
            @endif
            <x-ui.kpi icon="support" :label="__('sadmin_dashboard.kpi.open_tickets')" :value="number_format($support['open'])"
                :hint="trans_choice('sadmin_dashboard.kpi.urgent', $support['urgent'], ['count' => $support['urgent']])"
                :href="$link('platform.support.view', 'superadmin.support.index')" />
        </section>

        <section class="stat-strip" aria-label="{{ __('sadmin_dashboard.strip.label') }}">
            <a class="stat-strip__item" @if($url = $link('platform.center.view', 'superadmin.centers.index')) href="{{ route('superadmin.centers.index', ['status' => 'active']) }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.active') }}</span><strong>{{ number_format($centers['active']) }}</strong>
            </a>
            <a class="stat-strip__item" @if($link('platform.subscription.manage', 'superadmin.subscriptions.index')) href="{{ route('superadmin.subscriptions.index', ['status' => 'trialing']) }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.trial') }}</span><strong>{{ number_format($subscriptions['by_status']['trialing'] ?? 0) }}</strong>
            </a>
            <a class="stat-strip__item" @if($link('platform.center.view', 'superadmin.centers.index')) href="{{ route('superadmin.centers.index', ['status' => 'suspended']) }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.suspended') }}</span><strong>{{ number_format($centers['suspended']) }}</strong>
            </a>
            <a class="stat-strip__item" @if($link('platform.subscription.manage', 'superadmin.subscriptions.index')) href="{{ route('superadmin.subscriptions.index', ['cycle' => 'monthly']) }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.monthly') }}</span><strong>{{ number_format($subscriptions['by_cycle']['monthly'] ?? 0) }}</strong>
            </a>
            <a class="stat-strip__item" @if($link('platform.subscription.manage', 'superadmin.subscriptions.index')) href="{{ route('superadmin.subscriptions.index', ['cycle' => 'yearly']) }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.yearly') }}</span><strong>{{ number_format($subscriptions['by_cycle']['yearly'] ?? 0) }}</strong>
            </a>
            <a class="stat-strip__item" @if($link('platform.billing.manage', 'superadmin.billing.index')) href="{{ route('superadmin.billing.index') }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.invoices') }}</span><strong>{{ number_format($billing['issued']) }}</strong>
            </a>
            <a class="stat-strip__item" @if($link('platform.user.manage', 'superadmin.users.index')) href="{{ route('superadmin.users.index') }}" wire:navigate @endif>
                <span>{{ __('sadmin_dashboard.strip.users') }}</span><strong>{{ number_format($data['platform']['users']) }}</strong>
            </a>
            <a class="stat-strip__item" href="{{ route('superadmin.alerts.index') }}" wire:navigate>
                <span>{{ __('sadmin_dashboard.strip.alerts') }}</span><strong>{{ number_format($data['platform']['alerts']) }}</strong>
            </a>
        </section>

        {{-- Growth --}}
        <div class="dash-row">
            <section class="chart-card dash-span-8">
                <header class="chart-card__head">
                    <h2>{{ __('sadmin_dashboard.charts.new_centers') }}</h2>
                    <span class="chart-card__figure"><strong>{{ number_format($centers['new']) }}</strong></span>
                </header>
                <x-chart.columns :label="__('sadmin_dashboard.charts.new_centers')" :buckets="$buckets"
                    :series="[['label' => __('sadmin_dashboard.charts.new_centers'), 'values' => $centers['new_series']]]" />
            </section>
            <section class="chart-card dash-span-4">
                <header class="chart-card__head"><h2>{{ __('sadmin_dashboard.charts.centers_by_status') }}</h2></header>
                <x-chart.bars :label="__('sadmin_dashboard.charts.centers_by_status')"
                    :items="$statusItems($centers['by_status'], 'tenant_status', ['active', 'provisioning', 'suspended', 'cancelled', 'failed', 'archived'])" />
            </section>
        </div>

        {{-- Revenue --}}
        <div class="dash-row">
            <section class="chart-card dash-span-8">
                <header class="chart-card__head">
                    <h2>{{ __('sadmin_dashboard.charts.billed_vs_collected') }}</h2>
                    @if($lead)<span class="badge">{{ $lead['currency'] }}</span>@endif
                </header>
                @if($lead)
                    <x-chart.columns :label="__('sadmin_dashboard.charts.billed_vs_collected')" :buckets="$buckets" :currency="$lead['currency']"
                        :series="[
                            ['label' => __('sadmin_dashboard.charts.billed'), 'values' => $lead['billed_series']],
                            ['label' => __('sadmin_dashboard.charts.collected'), 'values' => $lead['collected_series']],
                        ]" />
                @else
                    <x-ui.empty-state compact icon="billing" :title="__('sadmin_dashboard.billing.empty')" />
                @endif
            </section>
            <section class="chart-card dash-span-4">
                <header class="chart-card__head"><h2>{{ __('sadmin_dashboard.charts.by_plan') }}</h2></header>
                <x-chart.bars :label="__('sadmin_dashboard.charts.by_plan')"
                    :items="array_map(fn ($plan) => ['label' => $plan['name'], 'value' => $plan['count']], $subscriptions['by_plan'])" />
            </section>
        </div>

        <div class="dash-row dash-row--pair">
            <section class="chart-card dash-span-6">
                <header class="chart-card__head"><h2>{{ __('sadmin_dashboard.charts.by_cycle') }}</h2></header>
                <x-chart.bars :label="__('sadmin_dashboard.charts.by_cycle')"
                    :items="$statusItems($subscriptions['by_cycle'], 'billing_period', ['monthly', 'yearly', 'quarterly'])" />
            </section>
            <section class="chart-card dash-span-6">
                <header class="chart-card__head"><h2>{{ __('sadmin_dashboard.charts.invoice_status') }}</h2></header>
                <x-chart.bars :label="__('sadmin_dashboard.charts.invoice_status')"
                    :items="$statusItems($billing['by_status'], 'invoice_status', ['issued', 'partially_paid', 'settled', 'void'])" />
            </section>
        </div>

        {{-- Billing + support summaries --}}
        <div class="dash-row dash-row--pair">
            <section class="chart-card dash-span-6">
                <header class="chart-card__head">
                    <h2>{{ __('sadmin_dashboard.billing.title') }}</h2>
                    @if($url = $link('platform.billing.manage', 'superadmin.billing.index'))
                        <x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$url" wire:navigate>{{ __('sadmin_dashboard.actions.billing') }}</x-ui.button>
                    @endif
                </header>
                <dl class="summary-list">
                    <div><dt>{{ __('sadmin_dashboard.billing.issued') }}</dt><dd>{{ number_format($billing['issued']) }}</dd></div>
                    <div><dt>{{ __('sadmin_dashboard.billing.overdue') }}</dt><dd>@if($billing['overdue'] > 0)<x-ui.status tone="danger">{{ number_format($billing['overdue']) }}</x-ui.status>@else 0 @endif</dd></div>
                    @forelse($billing['currencies'] as $currency)
                        <div><dt>{{ __('sadmin_dashboard.billing.billed') }} · {{ $currency['currency'] }}</dt><dd><x-ui.money :minor="$currency['billed']" :currency="$currency['currency']" /></dd></div>
                        <div><dt>{{ __('sadmin_dashboard.billing.collected') }} · {{ $currency['currency'] }}</dt><dd><x-ui.money :minor="$currency['collected']" :currency="$currency['currency']" /></dd></div>
                        <div><dt>{{ __('sadmin_dashboard.billing.outstanding') }} · {{ $currency['currency'] }}</dt><dd><x-ui.money :minor="$currency['outstanding']" :currency="$currency['currency']" /></dd></div>
                    @empty
                        <div><dt>{{ __('sadmin_dashboard.billing.outstanding') }}</dt><dd>—</dd></div>
                    @endforelse
                </dl>
            </section>
            <section class="chart-card dash-span-6">
                <header class="chart-card__head">
                    <h2>{{ __('sadmin_dashboard.support.title') }}</h2>
                    @if($url = $link('platform.support.view', 'superadmin.support.index'))
                        <x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$url" wire:navigate>{{ __('sadmin_dashboard.actions.support') }}</x-ui.button>
                    @endif
                </header>
                <dl class="kv-grid">
                    <div><dt>{{ __('sadmin_dashboard.support.new') }}</dt><dd>{{ number_format($support['new']) }}</dd></div>
                    <div><dt>{{ __('sadmin_dashboard.support.resolved') }}</dt><dd>{{ number_format($support['resolved']) }}</dd></div>
                    <div><dt>{{ __('sadmin_dashboard.support.open') }}</dt><dd>{{ number_format($support['open']) }}</dd></div>
                </dl>
                @if($support['latest'] === [])
                    <p class="chart__empty">{{ __('sadmin_dashboard.support.empty') }}</p>
                @else
                    <ul class="list row-list row-list--flush">
                        @foreach($support['latest'] as $ticket)
                            <li class="row-list__item">
                                <div class="row-list__body">
                                    <a class="cell-title" href="{{ Route::has('superadmin.support.show') ? route('superadmin.support.show', $ticket->uuid) : '#' }}" wire:navigate>{{ $ticket->subject }}</a>
                                    <span class="cell-sub"><span class="ltr">{{ $ticket->reference }}</span> · {{ $ticket->center_name ?? '—' }}</span>
                                </div>
                                <x-ui.status :value="in_array($ticket->priority, ['urgent', 'high'], true) ? 'critical' : 'info'" :label="Label::for('ticket_priority', $ticket->priority)" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Registrations + activity --}}
        <div class="dash-row dash-row--pair">
            <section class="chart-card chart-card--flush dash-span-6">
                <header class="chart-card__head">
                    <h2>{{ __('sadmin_dashboard.registrations.title') }}</h2>
                    @if($url = $link('platform.center.view', 'superadmin.centers.index'))
                        <x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$url" wire:navigate>{{ __('sadmin_dashboard.actions.centers') }}</x-ui.button>
                    @endif
                </header>
                @if($data['registrations'] === [])
                    <x-ui.empty-state compact icon="user-plus" :title="__('sadmin_dashboard.registrations.empty')" />
                @else
                    <ul class="list row-list">
                        @foreach($data['registrations'] as $registration)
                            <li class="row-list__item">
                                <div class="row-list__body">
                                    <span class="cell-title">{{ $registration->center_name }}</span>
                                    <span class="cell-sub">@if($registration->requested_slug)<span class="ltr">{{ $registration->requested_slug }}</span> · @endif{{ \Illuminate\Support\Carbon::parse($registration->created_at)->diffForHumans() }}</span>
                                </div>
                                <x-ui.status :value="$registration->status === 'pending_verification' ? 'pending' : $registration->status" :label="Label::for('registration_status', $registration->status)" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section class="chart-card dash-span-6">
                <header class="chart-card__head">
                    <h2>{{ __('sadmin_dashboard.activity.title') }}</h2>
                    @if($url = $link('platform.audit.view', 'superadmin.audit.index'))
                        <x-ui.button variant="ghost" size="sm" icon-after="arrow-right" :href="$url" wire:navigate>{{ __('sadmin_dashboard.actions.audit') }}</x-ui.button>
                    @endif
                </header>
                @if($data['activity'] === [])
                    <p class="chart__empty">{{ __('sadmin_dashboard.activity.empty') }}</p>
                @else
                    <ol class="timeline">
                        @foreach($data['activity'] as $entry)
                            <li class="timeline__item">
                                <span class="timeline__dot" data-tone="{{ in_array($entry->severity, ['critical', 'warning'], true) ? 'danger' : 'primary' }}"><x-ui.icon name="dot" /></span>
                                <div class="timeline__body">
                                    <strong class="break-anywhere">{{ \App\View\AuditAction::label($entry->action) }}</strong>
                                    <div class="timeline__meta">
                                        {{ \App\View\AuditAction::target($entry->target_type ?? null, $entry->target_label) ?: Label::for('audit_category', $entry->category) }}@if($entry->actor_label) · {{ \App\View\AuditAction::actor($entry->actor_label, $entry->actor_type ?? null) }}@endif
                                        · <time datetime="{{ $entry->occurred_at }}">{{ \Illuminate\Support\Carbon::parse($entry->occurred_at)->diffForHumans() }}</time>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</div>
