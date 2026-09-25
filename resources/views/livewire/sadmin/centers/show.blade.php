@php
    use App\View\AuditAction;
    use App\View\Label;

    $lifecycleLabel = fn (string $target): string => $target === 'active' && ($tenant->status ?? null) === 'archived' ? 'restore' : $target;

    $money = app(\App\Kernel\Platform\Currencies\PlatformCurrencies::class);
    $initials = mb_strtoupper(mb_substr(trim((string) $tenant->name), 0, 2));
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : '—';
    $dateTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y, H:i') : '—';
    $tabs = collect(\App\Livewire\Sadmin\Centers\Show::TABS)
        ->reject(fn ($key) => ($key === 'billing' && ! $can['billing']) || ($key === 'support' && ! $can['support']) || ($key === 'audit' && ! $can['audit']))
        ->all();
    $lifecycleIcon = ['suspended' => 'pause', 'active' => 'play', 'cancelled' => 'x-circle', 'archived' => 'archive'];
    $lifecycleTone = ['suspended' => 'warning', 'active' => null, 'cancelled' => 'danger', 'archived' => 'danger'];
    $currency = $tenant->currency ?: $defaultCurrency;
    $planPrice = function ($plan, string $cycle) use ($money): ?string {
        $minor = $plan->priceFor($cycle);

        return $minor === null ? null : $money->format($minor, $plan->currency, app()->getLocale());
    };
@endphp

<div class="stack">
    <nav aria-label="{{ __('sadmin_centers.detail.breadcrumb') }}">
        <ol class="breadcrumbs">
            <li><a href="{{ route('superadmin.centers.index') }}" wire:navigate>{{ __('sadmin_centers.title') }}</a></li>
            <li><span aria-current="page">{{ $tenant->name }}</span></li>
        </ol>
    </nav>

    <header class="record-header">
        <div class="record-header__identity">
            <span class="record-header__mark" aria-hidden="true">{{ $initials }}</span>
            <div>
                <h1>{{ $tenant->name }}</h1>
                <div class="record-header__meta">
                    <x-ui.status :value="$tenant->status" :label="Label::for('tenant_status', $tenant->status)" />
                    @if($commercial)
                        <x-ui.status :value="$commercial['status']" :label="$commercial['plan'].' · '.Label::for('subscription_status', $commercial['status'])" :dot="false" />
                        @if($commercial['cycle'])<span class="chip">{{ Label::for('billing_period', $commercial['cycle']) }}</span>@endif
                    @endif
                    <span class="chip" dir="ltr">{{ $currency }}</span>
                    @if($address['available'])
                        <a class="cell-link" href="{{ $address['urls']['public'] }}" target="_blank" rel="noopener" dir="ltr">{{ $address['host'] }}</a>
                    @endif
                    <span class="muted">{{ __('sadmin_centers.detail.created_on', ['date' => $date($tenant->created_at)]) }}</span>
                </div>
            </div>
        </div>
        <div class="cluster">
            @if($address['available'])
                <a class="button button--secondary" href="{{ $address['urls']['login'] }}" target="_blank" rel="noopener"><x-ui.icon name="key" size="16" />{{ __('sadmin_centers.actions.open_login') }}</a>
            @endif
            @if($can['manage'])
                <button class="button button--secondary" type="button" wire:click="openPanel('edit')"><x-ui.icon name="edit" size="16" />{{ __('sadmin_centers.actions.edit') }}</button>
            @endif
            <details class="dropdown" data-popover>
                <summary class="button button--secondary" aria-label="{{ __('sadmin_centers.actions.more', ['name' => $tenant->name]) }}">{{ __('sadmin_centers.actions.manage') }}<x-ui.icon name="chevron-down" size="16" /></summary>
                <div class="dropdown__panel" role="menu">
                    @if($can['subscription'] && $subscription)
                        <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('plan')"><x-ui.icon name="plans" />{{ __('sadmin_centers.actions.change_plan') }}</button>
                        @if($commercial['canActivate'])
                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('activate')"><x-ui.icon name="check-circle" />{{ __('sadmin_centers.actions.activate') }}</button>
                        @endif
                        @if($commercial['isTrial'])
                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('trial')"><x-ui.icon name="clock" />{{ __('sadmin_centers.actions.extend_trial') }}</button>
                        @endif
                    @endif
                    @if($can['billing'])
                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.billing.index', ['center' => $tenant->id, 'panel' => 'issue']) }}" wire:navigate><x-ui.icon name="receipt" />{{ __('sadmin_centers.actions.issue_invoice') }}</a>
                    @endif
                    @if($can['manage'] && $transitions !== [])
                        <div class="menu-separator" role="separator"></div>
                        @foreach($transitions as $target)
                            <button class="menu-item {{ in_array($target, ['cancelled', 'archived'], true) ? 'menu-item--danger' : '' }}" role="menuitem" type="button" wire:click="openPanel('lifecycle:{{ $target }}')">
                                <x-ui.icon :name="$lifecycleIcon[$target]" />{{ __('sadmin_centers.lifecycle.confirm.'.$lifecycleLabel($target)) }}
                            </button>
                        @endforeach
                    @endif
                    @if($address['available'])
                        <div class="menu-separator" role="separator"></div>
                        <a class="menu-item" role="menuitem" href="{{ $address['urls']['public'] }}" target="_blank" rel="noopener"><x-ui.icon name="external" />{{ __('sadmin_centers.actions.open_site') }}</a>
                        <button class="menu-item" role="menuitem" type="button" data-copy="{{ $address['urls']['public'] }}" data-copied="{{ __('sadmin_centers.actions.copied') }}"><x-ui.icon name="copy" />{{ __('sadmin_centers.actions.copy_site') }}</button>
                        <button class="menu-item" role="menuitem" type="button" data-copy="{{ $address['urls']['login'] }}" data-copied="{{ __('sadmin_centers.actions.copied') }}"><x-ui.icon name="copy" />{{ __('sadmin_centers.actions.copy_login') }}</button>
                    @endif
                </div>
            </details>
        </div>
    </header>

    <x-ui.flash />

    <nav class="tabs tabs--scroll" aria-label="{{ __('sadmin_centers.detail.tabs.label') }}">
        @foreach($tabs as $key)
            <button type="button" wire:click="showTab('{{ $key }}')" @if($tab === $key) aria-current="page" @endif>{{ __('sadmin_centers.detail.tabs.'.$key) }}</button>
        @endforeach
    </nav>

    <div class="stack" wire:loading.class="is-refreshing" wire:target="showTab">
    @switch($tab)
        {{-- ── People ─────────────────────────────────────────────────── --}}
        @case('people')
            <x-ui.card :title="__('sadmin_centers.people.title')" flush>
                @if($can['center_users'])
                    <x-slot:actions>
                        <a class="button button--ghost button--sm" href="{{ route('superadmin.center-users.index', ['center' => $tenant->id]) }}" wire:navigate><x-ui.icon name="users" size="16" />{{ __('sadmin_centers.people.directory') }}</a>
                    </x-slot:actions>
                @endif
                @if($data['people'] === [])
                    <x-ui.empty-state compact icon="users" :title="__('sadmin_centers.people.empty')" />
                @else
                    <div class="table-shell table-shell--stack">
                        <table>
                            <caption class="sr-only">{{ __('sadmin_centers.people.title') }}</caption>
                            <thead><tr>
                                <th scope="col">{{ __('sadmin_centers.people.name') }}</th>
                                <th scope="col">{{ __('sadmin_centers.people.contact') }}</th>
                                <th scope="col">{{ __('sadmin_centers.people.role') }}</th>
                                <th scope="col">{{ __('sadmin_centers.people.branches') }}</th>
                                <th scope="col">{{ __('sadmin_centers.people.status') }}</th>
                                <th scope="col">{{ __('sadmin_centers.people.last_sign_in') }}</th>
                                <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                            </tr></thead>
                            <tbody>
                                @foreach($data['people'] as $person)
                                    <tr wire:key="person-{{ $person['uuid'] }}">
                                        <td data-label="{{ __('sadmin_centers.people.name') }}" data-primary>
                                            <span class="cell-title">{{ $person['name'] }}</span>
                                            <span class="cell-sub">
                                                @if($person['owner'])<span class="badge" data-tone="primary">{{ __('sadmin_centers.people.owner') }}</span>@endif
                                                {{ __('sadmin_centers.people.since', ['date' => $date($person['created_at'])]) }}
                                            </span>
                                        </td>
                                        <td data-label="{{ __('sadmin_centers.people.contact') }}">
                                            @if($person['email'])<span class="cell-title" dir="ltr">{{ $person['email'] }}</span>@endif
                                            @if($person['phone'])<span class="cell-sub" dir="ltr">{{ $person['phone'] }}</span>@else<x-ui.status tone="warning" :dot="false" :label="__('phone_field.missing')" />@endif
                                        </td>
                                        <td data-label="{{ __('sadmin_centers.people.role') }}">{{ $person['roles'] === [] ? '—' : implode(', ', $person['roles']) }}</td>
                                        <td data-label="{{ __('sadmin_centers.people.branches') }}">{{ $person['all_branches'] ? __('sadmin_centers.people.all_branches') : ($person['branches'] === [] ? '—' : implode(', ', $person['branches'])) }}</td>
                                        <td data-label="{{ __('sadmin_centers.people.status') }}"><x-ui.status :value="$person['active'] ? 'active' : 'blocked'" :tone="$person['active'] ? 'success' : 'danger'" :label="__('sadmin_centers.people.'.($person['active'] ? 'active' : 'blocked'))" /></td>
                                        <td data-label="{{ __('sadmin_centers.people.last_sign_in') }}">{{ $person['last_login_at']?->diffForHumans() ?? __('sadmin_centers.people.never') }}</td>
                                        <td class="actions">
                                            @if($can['manage'])
                                                <details class="dropdown" data-popover>
                                                    <summary class="icon-button icon-button--sm" aria-label="{{ __('sadmin_centers.people.actions', ['name' => $person['name']]) }}"><x-ui.icon name="more" /></summary>
                                                    <div class="dropdown__panel" role="menu">
                                                        @if($person['active'] && $person['email'])
                                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('person-link:{{ $person['uuid'] }}')"><x-ui.icon name="mail" />{{ __('sadmin_centers.people.send_link') }}</button>
                                                        @endif
                                                        @if($person['active'] && ! $person['owner'])
                                                            <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="openPanel('person-block:{{ $person['uuid'] }}')"><x-ui.icon name="user-x" />{{ __('sadmin_centers.people.block') }}</button>
                                                        @elseif(! $person['active'])
                                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('person-unblock:{{ $person['uuid'] }}')"><x-ui.icon name="user-check" />{{ __('sadmin_centers.people.reactivate') }}</button>
                                                        @endif
                                                        @if($person['owner'] && $person['active'] && ! $person['email'])
                                                            <span class="menu-note">{{ __('sadmin_centers.people.owner_protected') }}</span>
                                                        @endif
                                                    </div>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
            @break

        {{-- ── Subscription ───────────────────────────────────────────── --}}
        @case('subscription')
            <div class="record-grid">
                <x-ui.card :title="__('sadmin_centers.detail.commercial.title')">
                    @if($subscription)
                        @if($can['subscription'])
                            <x-slot:actions>
                                <button class="button button--sm" type="button" wire:click="openPanel('plan')"><x-ui.icon name="plans" size="16" />{{ __('sadmin_centers.actions.change_plan') }}</button>
                            </x-slot:actions>
                        @endif
                        <dl class="kv-grid">
                            <div><dt>{{ __('sadmin_centers.detail.commercial.plan') }}</dt><dd>{{ $commercial['plan'] }}</dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.commercial.status') }}</dt><dd><x-ui.status :value="$commercial['status']" :label="Label::for('subscription_status', $commercial['status'])" /></dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.commercial.cycle') }}</dt><dd>{{ Label::for('billing_period', $commercial['cycle']) }}</dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.commercial.price') }}</dt><dd dir="ltr" class="tabular">{{ $commercial['price'] ?? '—' }}</dd></div>
                            @if($subscription->trial_ends_at)
                                <div><dt>{{ __('sadmin_centers.detail.commercial.trial_ends') }}</dt><dd>{{ $date($subscription->trial_ends_at) }}@if($commercial['trial_days'] !== null)<span class="cell-sub">{{ trans_choice('sadmin_centers.cell.trial_left', $commercial['trial_days'], ['count' => $commercial['trial_days']]) }}</span>@endif</dd></div>
                            @endif
                            <div><dt>{{ __('sadmin_centers.detail.commercial.current_period') }}</dt><dd>{{ $date($subscription->current_period_start) }} – {{ $date($subscription->current_period_end) }}</dd></div>
                            @if($subscription->cancelled_at)
                                <div><dt>{{ __('sadmin_centers.detail.commercial.cancelled_at') }}</dt><dd>{{ $date($subscription->cancelled_at) }}</dd></div>
                            @endif
                        </dl>
                        @if($can['subscription'])
                            <div class="cluster action-row">
                                @if($commercial['canActivate'])
                                    <button class="button button--secondary button--sm" type="button" wire:click="openPanel('activate')"><x-ui.icon name="check-circle" size="16" />{{ __('sadmin_centers.actions.activate') }}</button>
                                @endif
                                @if($commercial['isTrial'])
                                    <button class="button button--secondary button--sm" type="button" wire:click="openPanel('trial')"><x-ui.icon name="clock" size="16" />{{ __('sadmin_centers.actions.extend_trial') }}</button>
                                @else
                                    <button class="button button--secondary button--sm" type="button" wire:click="openPanel('renewal')"><x-ui.icon name="calendar" size="16" />{{ __('sadmin_centers.actions.set_renewal') }}</button>
                                @endif
                                <button class="button button--ghost button--sm" type="button" wire:click="openPanel('schedule')"><x-ui.icon name="clock" size="16" />{{ __('sadmin_centers.schedule.open') }}</button>
                                @if($can['manage'] && in_array($tenant->status, ['active', 'suspended'], true))
                                    <button class="button button--ghost button--sm" type="button" wire:click="openPanel('lifecycle:{{ $tenant->status === 'active' ? 'suspended' : 'active' }}')">{{ __('sadmin_centers.lifecycle.confirm.'.($tenant->status === 'active' ? 'suspended' : 'active')) }}</button>
                                @endif
                            </div>
                        @endif
                    @else
                        <x-ui.empty-state compact icon="subscriptions" :title="__('sadmin_centers.detail.commercial.none')" />
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('sadmin_centers.subscription.history')">
                    @if($data['history']->isEmpty())
                        <x-ui.empty-state compact icon="history" :title="__('sadmin_centers.subscription.history_empty')" />
                    @else
                        <ol class="timeline">
                            @foreach($data['history'] as $entry)
                                <li class="timeline__item" wire:key="sub-history-{{ $entry->id }}">
                                    <span class="timeline__dot" data-tone="{{ in_array($entry->event, ['activated', 'created'], true) ? 'success' : 'info' }}" aria-hidden="true"><x-ui.icon name="dot" /></span>
                                    <div class="timeline__body">
                                        <strong>{{ __('sadmin_centers.subscription.events.'.$entry->event) }}</strong>
                                        <p>
                                            @if($entry->from_plan_id && $entry->from_plan_id !== $entry->to_plan_id)
                                                {{ $entry->fromPlan?->name?->get() ?? '—' }} → {{ $entry->toPlan?->name?->get() ?? '—' }}
                                            @elseif($entry->toPlan)
                                                {{ $entry->toPlan->name->get() }}
                                            @endif
                                            @if($entry->to_cycle) · {{ $entry->from_cycle && $entry->from_cycle !== $entry->to_cycle ? Label::for('billing_period', $entry->from_cycle).' → ' : '' }}{{ Label::for('billing_period', $entry->to_cycle) }}@endif
                                            @if($entry->price_minor !== null && $entry->currency) · <span dir="ltr" class="tabular">{{ $money->format($entry->price_minor, $entry->currency, app()->getLocale()) }}</span>@endif
                                            @if($entry->from_status && $entry->from_status !== $entry->to_status) · {{ Label::for('subscription_status', $entry->from_status) }} → {{ Label::for('subscription_status', $entry->to_status) }}@endif
                                        </p>
                                        @if($entry->reason)<p class="muted">{{ $entry->reason }}</p>@endif
                                        <div class="timeline__meta">{{ AuditAction::actor($entry->actor_label) ?? __('sadmin_centers.history.system') }} · <time datetime="{{ $entry->occurred_at->toIso8601String() }}">{{ $dateTime($entry->occurred_at) }}</time></div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-ui.card>
            </div>

            @if($data['scheduled']->isNotEmpty())
                <x-ui.card :title="__('sadmin_centers.schedule.title')" flush>
                    <ul class="row-list">
                        @foreach($data['scheduled'] as $change)
                            <li class="row-list__item" wire:key="scheduled-{{ $change->id }}">
                                <div class="row-list__body">
                                    <span class="cell-title">{{ $data['planNames'][$change->target_plan_id]?->get() ?? '—' }}</span>
                                    <span class="cell-sub">{{ __('sadmin_centers.schedule.effective') }}: {{ $dateTime($change->effective_at) }}@if($change->reason) · {{ $change->reason }}@endif</span>
                                </div>
                                <x-ui.status :value="$change->status" :label="__('sadmin_centers.schedule.statuses.'.$change->status)" :dot="false" />
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
            @break

        {{-- ── Capabilities ───────────────────────────────────────────── --}}
        @case('entitlements')
            <div class="stack">
            @foreach($data['groups'] as $category => $rows)
                <x-ui.card :title="__('platform_labels.entitlement_category.'.$category)" flush>
                    <div class="table-shell table-shell--stack">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('sadmin_centers.entitlements.capability') }}</th>
                                    <th scope="col">{{ __('sadmin_centers.entitlements.plan') }}</th>
                                    <th scope="col">{{ __('sadmin_centers.entitlements.override') }}</th>
                                    <th scope="col">{{ __('sadmin_centers.entitlements.effective') }}</th>
                                    <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr wire:key="entitlement-{{ $row['key'] }}">
                                        <td data-label="{{ __('sadmin_centers.entitlements.capability') }}" data-primary>
                                            <span class="cell-title">{{ __('platform_labels.entitlement.'.$row['key']) }}</span>
                                            @if($row['requires'] !== [])
                                                <span class="cell-sub">{{ __('sadmin_centers.entitlements.requires', ['list' => collect($row['requires'])->map(fn ($key) => __('platform_labels.entitlement.'.$key))->join(', ')]) }}</span>
                                            @endif
                                        </td>
                                        <td data-label="{{ __('sadmin_centers.entitlements.plan') }}">
                                            <span class="{{ $row['in_plan'] ? '' : 'muted' }}">{{ __('platform_labels.entitlement_source.'.($row['in_plan'] ? 'plan' : 'not_in_plan')) }}</span>
                                        </td>
                                        <td data-label="{{ __('sadmin_centers.entitlements.override') }}">
                                            @if($row['override'])
                                                <x-ui.status :value="$row['override']->mode->value === 'grant' ? 'active' : 'suspended'" :label="__('platform_labels.entitlement_source.'.($row['override']->mode->value === 'grant' ? 'granted' : 'revoked'))" :dot="false" />
                                                <span class="cell-sub">{{ $row['override']->expires_at ? __('sadmin_centers.entitlements.expires', ['date' => $date($row['override']->expires_at)]) : __('sadmin_centers.entitlements.no_expiry') }}</span>
                                            @else
                                                <span class="muted">{{ __('sadmin_centers.entitlements.none') }}</span>
                                            @endif
                                        </td>
                                        <td data-label="{{ __('sadmin_centers.entitlements.effective') }}">
                                            <x-ui.status :value="$row['owned'] ? 'enabled' : 'disabled'" :label="__('sadmin_centers.entitlements.'.($row['owned'] ? 'on' : 'off'))" />
                                        </td>
                                        <td class="actions">
                                            @if($can['entitlements'])
                                                @if($row['override'])
                                                    <button class="button button--ghost button--sm" type="button" wire:click="openPanel('entitlement-reset:{{ $row['override']->id }}')">{{ __('sadmin_centers.entitlements.reset') }}</button>
                                                @endif
                                                <button class="button button--secondary button--sm" type="button" wire:click="openPanel('entitlement:{{ $row['key'] }}')">{{ __('sadmin_centers.entitlements.change') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endforeach
            </div>
            @break

        {{-- ── Usage ──────────────────────────────────────────────────── --}}
        @case('usage')
            <x-ui.card :title="__('sadmin_centers.usage.title')" flush>
                <div class="table-shell table-shell--stack">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('sadmin_centers.usage.resource') }}</th>
                                <th scope="col">{{ __('sadmin_centers.usage.used') }}</th>
                                <th scope="col">{{ __('sadmin_centers.usage.allowance') }}</th>
                                <th scope="col">{{ __('sadmin_centers.usage.override') }}</th>
                                <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_centers.table.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['rows'] as $row)
                                @php
                                    $percent = $row['allowance'] !== null && $row['allowance'] > 0 && $row['used'] !== null ? min(100, (int) round($row['used'] * 100 / $row['allowance'])) : null;
                                    $tone = match ($row['status']) { 'exhausted' => 'danger', 'high', 'warning' => 'warning', default => 'success' };
                                @endphp
                                <tr wire:key="usage-{{ $row['code'] }}">
                                    <td data-label="{{ __('sadmin_centers.usage.resource') }}" data-primary>
                                        <span class="cell-title">{{ Label::for('usage_resource', $row['code']) }}</span>
                                        @if($row['period_end'])<span class="cell-sub">{{ __('sadmin_centers.usage.resets', ['date' => $date($row['period_end'])]) }}</span>@endif
                                    </td>
                                    <td data-label="{{ __('sadmin_centers.usage.used') }}">
                                        <div class="usage-progress">
                                            <span class="tabular"><strong>{{ number_format((int) $row['used']) }}</strong>@if($row['allowance'] !== null)<span class="muted"> / {{ number_format($row['allowance']) }}</span>@endif</span>
                                            @if($percent !== null)
                                                <div class="progress" data-tone="{{ $tone }}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ Label::for('usage_resource', $row['code']) }}"><div class="progress__bar" style="width: {{ $percent }}%"></div></div>
                                            @endif
                                        </div>
                                    </td>
                                    <td data-label="{{ __('sadmin_centers.usage.allowance') }}">
                                        @if(! $row['enforced'])
                                            <span class="muted">{{ __('sadmin_centers.usage.metered') }}</span>
                                        @else
                                            {{ $row['allowance'] === null ? __('sadmin_centers.usage.unlimited') : number_format($row['allowance']) }}
                                        @endif
                                        @if($row['status'])<span class="cell-sub">{{ Label::for('usage_status', $row['status']) }}</span>@endif
                                    </td>
                                    <td data-label="{{ __('sadmin_centers.usage.override') }}">
                                        @if($row['override'])
                                            <x-ui.status value="active" :label="$row['override']->allowance === null ? __('sadmin_centers.usage.unlimited') : number_format($row['override']->allowance)" :dot="false" />
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                    <td class="actions">
                                        @if($can['usage'] && ($row['enforced'] || $row['override']))
                                            @if($row['override'])
                                                <button class="button button--ghost button--sm" type="button" wire:click="openPanel('usage-reset:{{ $row['override']->id }}')">{{ __('sadmin_centers.usage.reset') }}</button>
                                            @endif
                                            <button class="button button--secondary button--sm" type="button" wire:click="openPanel('usage:{{ $row['code'] }}')">{{ __('sadmin_centers.usage.change') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
            @break

        {{-- ── Billing ────────────────────────────────────────────────── --}}
        @case('billing')
            @php $billing = $data; $st = $billing['statement']; @endphp
            <div class="stat-grid">
                <x-ui.stat :label="__('sadmin_centers.billing.plan')" :value="$commercial['plan'] ?? '—'" :hint="($commercial['cycle'] ?? null) ? Label::for('billing_period', $commercial['cycle']) : null" icon="plans" />
                <x-ui.stat :label="__('sadmin_centers.billing.currency')" :value="$tenant->currency ?? '—'" icon="coins" />
                @forelse($billing['outstanding'] as $due)
                    <x-ui.stat :label="__('sadmin_centers.billing.outstanding')" :value="$due['amount']" :hint="trans_choice('sadmin_centers.billing.open_invoices', $due['invoices'], ['count' => $due['invoices']])" icon="wallet" tone="warning" />
                @empty
                    <x-ui.stat :label="__('sadmin_centers.billing.outstanding')" :value="__('sadmin_centers.billing.nothing_due')" icon="wallet" tone="success" />
                @endforelse
            </div>

            <x-ui.card :title="__('sadmin_centers.billing.title')" flush>
                <x-slot:actions>
                    <a class="button button--sm" href="{{ route('superadmin.billing.index', ['center' => $tenant->id, 'panel' => 'issue']) }}" wire:navigate><x-ui.icon name="plus" size="16" />{{ __('sadmin_centers.actions.issue_invoice') }}</a>
                    <a class="button button--secondary button--sm" href="{{ route('superadmin.billing.index', ['center' => $tenant->id]) }}" wire:navigate>{{ trans_choice('sadmin_centers.billing.view_all', $billing['invoiceCount'], ['count' => $billing['invoiceCount']]) }}</a>
                </x-slot:actions>
                @if($billing['invoices']->isEmpty())
                    <x-ui.empty-state compact icon="receipt" :title="__('sadmin_centers.billing.none')" />
                @else
                    <div class="table-shell table-shell--stack">
                        <table>
                            <thead><tr>
                                <th scope="col">{{ __('sadmin_centers.billing.number') }}</th>
                                <th scope="col">{{ __('sadmin_centers.billing.status') }}</th>
                                <th scope="col">{{ __('sadmin_centers.billing.cycle') }}</th>
                                <th scope="col" class="numeric">{{ __('sadmin_centers.billing.total') }}</th>
                                <th scope="col" class="numeric">{{ __('sadmin_centers.billing.paid') }}</th>
                                <th scope="col">{{ __('sadmin_centers.billing.due') }}</th>
                                <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                            </tr></thead>
                            <tbody>
                                @foreach($billing['invoices'] as $invoice)
                                    <tr wire:key="invoice-{{ $invoice->id }}">
                                        <td data-label="{{ __('sadmin_centers.billing.number') }}" data-primary><a class="cell-title" dir="ltr" href="{{ route('superadmin.billing.index', ['invoice' => $invoice->uuid]) }}" wire:navigate>{{ $invoice->number }}</a><span class="cell-sub">{{ $date($invoice->issued_at) }}</span></td>
                                        <td data-label="{{ __('sadmin_centers.billing.status') }}"><x-ui.status :value="$invoice->isOverdue() ? 'overdue' : $invoice->status" :label="Label::for('invoice_status', $invoice->isOverdue() ? 'overdue' : $invoice->status)" /></td>
                                        <td data-label="{{ __('sadmin_centers.billing.cycle') }}">{{ $invoice->billing_period ? Label::for('billing_period', $invoice->billing_period) : '—' }}</td>
                                        <td data-label="{{ __('sadmin_centers.billing.total') }}" class="numeric"><x-ui.money :minor="$invoice->total_minor" :currency="$invoice->currency" /></td>
                                        <td data-label="{{ __('sadmin_centers.billing.paid') }}" class="numeric"><x-ui.money :minor="$invoice->paid_minor" :currency="$invoice->currency" /></td>
                                        <td data-label="{{ __('sadmin_centers.billing.due') }}">{{ $date($invoice->due_at) }}</td>
                                        <td class="actions">
                                            @if($can['billing'])
                                                <a class="icon-button" href="{{ route('superadmin.billing.invoice.print', ['invoice' => $invoice->uuid, 'print' => 1]) }}" target="_blank" rel="noopener" title="{{ __('sadmin_billing.actions.print') }}" aria-label="{{ __('sadmin_billing.actions.print') }}: {{ $invoice->number }}"><x-ui.icon name="print" size="16" /></a>
                                                <a class="icon-button" href="{{ route('superadmin.billing.invoice.pdf', ['invoice' => $invoice->uuid]) }}" title="{{ __('sadmin_billing.actions.pdf') }}" aria-label="{{ __('sadmin_billing.actions.pdf') }}: {{ $invoice->number }}"><x-ui.icon name="download" size="16" /></a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card :title="__('sadmin_centers.billing.settlements')" flush>
                @if($billing['payments']->isEmpty())
                    <x-ui.empty-state compact icon="wallet" :title="__('sadmin_centers.billing.no_settlements')" />
                @else
                    <ul class="row-list">
                        @foreach($billing['payments'] as $payment)
                            <li class="row-list__item" wire:key="settlement-{{ $payment->id }}">
                                <div class="row-list__body">
                                    <span class="cell-title" dir="ltr" @class(['struck' => $payment->reversed_at])><x-ui.money :minor="$payment->amount_minor" :currency="$payment->currency" /></span>
                                    <span class="cell-sub">{{ $date($payment->received_at) }} · {{ Label::for('payment_method', $payment->method) }} · <span dir="ltr">{{ $billing['paymentInvoices'][$payment->invoice_id] ?? '—' }}</span>@if($payment->reference) · <span dir="ltr">{{ $payment->reference }}</span>@endif</span>
                                </div>
                                @if($payment->reversed_at)<x-ui.status value="reversed" tone="danger" :label="__('sadmin_billing.detail.reversed')" :dot="false" />@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <section class="card card--flush" id="statement" aria-labelledby="statement-title">
                <header class="card__header">
                    <div>
                        <h2 id="statement-title">{{ __('sadmin_centers.statement.title') }}</h2>
                        <p>{{ __('sadmin_centers.statement.help') }}</p>
                    </div>
                </header>
                <div class="card__body stack">
                    <div class="form-grid">
                        <x-ui.field :label="__('sadmin_centers.statement.period')" for="statement-preset" name="statementPreset">
                            <select id="statement-preset" wire:model.live="statementPreset">
                                @foreach(\App\Modules\SaasBilling\Domain\StatementPeriod::PRESETS as $preset)<option value="{{ $preset }}">{{ __('sadmin_centers.statement.presets.'.$preset) }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        @if($statementPreset === 'custom')
                            <x-ui.field :label="__('sadmin_centers.statement.from')" for="statement-from" name="statementFrom">
                                <input id="statement-from" type="date" wire:model.live="statementFrom">
                            </x-ui.field>
                            <x-ui.field :label="__('sadmin_centers.statement.to')" for="statement-to" name="statementTo">
                                <input id="statement-to" type="date" wire:model.live="statementTo">
                            </x-ui.field>
                        @endif
                        <x-ui.field :label="__('sadmin_centers.statement.status')" for="statement-status" name="statementStatus">
                            <select id="statement-status" wire:model.live="statementStatus">
                                <option value="">{{ __('sadmin_centers.statement.all') }}</option>
                                @foreach(\App\Modules\SaasBilling\Application\SaasAccountStatement::STATUSES as $status)<option value="{{ $status }}">{{ Label::for('invoice_status', $status) }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('sadmin_centers.statement.method')" for="statement-method" name="statementMethod">
                            <select id="statement-method" wire:model.live="statementMethod">
                                <option value="">{{ __('sadmin_centers.statement.all') }}</option>
                                @foreach(\App\Modules\SaasBilling\Application\SaasAccountStatement::METHODS as $method)<option value="{{ $method }}">{{ Label::for('payment_method', $method) }}</option>@endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('sadmin_centers.statement.currency')" for="statement-currency" name="statementCurrency">
                            <select id="statement-currency" wire:model.live="statementCurrency">
                                <option value="">{{ __('sadmin_centers.statement.all') }}</option>
                                @foreach($billing['statementCurrencies'] as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach
                            </select>
                        </x-ui.field>
                    </div>
                    <p class="field-help">{{ __('sadmin_centers.statement.range', ['from' => $st['period']->from->translatedFormat('j M Y'), 'to' => $st['period']->to->translatedFormat('j M Y')]) }}@if($st['filtered']) · {{ __('sadmin_centers.statement.filtered') }}@endif</p>

                    @forelse($st['groups'] as $group)
                        <div class="statement-summary" wire:key="statement-{{ $group['currency'] }}">
                            <h3 dir="ltr">{{ $group['currency'] }}</h3>
                            <dl class="kv-grid">
                                @if($group['opening'] !== null)<div><dt>{{ __('saas_documents.statement.opening') }}</dt><dd dir="ltr">{{ $group['opening'] }}</dd></div>@endif
                                <div><dt>{{ __('saas_documents.statement.invoiced') }}</dt><dd dir="ltr">{{ $group['invoiced'] }}</dd></div>
                                <div><dt>{{ __('saas_documents.statement.paid') }}</dt><dd dir="ltr">{{ $group['paid'] }}</dd></div>
                                <div><dt>{{ __('saas_documents.statement.reversed') }}</dt><dd dir="ltr">{{ $group['reversed'] }}</dd></div>
                                <div><dt>{{ __('saas_documents.statement.voided') }}</dt><dd dir="ltr">{{ $group['voided'] }}</dd></div>
                                @if($group['closing'] !== null)<div><dt>{{ __('saas_documents.statement.closing') }}</dt><dd dir="ltr"><strong>{{ $group['closing'] }}</strong></dd></div>@endif
                            </dl>
                            <p class="cell-sub">{{ trans_choice('sadmin_centers.statement.lines', $group['lines'], ['count' => $group['lines']]) }}</p>
                        </div>
                    @empty
                        <p class="muted">{{ __('saas_documents.statement.empty') }}</p>
                    @endforelse
                </div>
                @if($can['billing'])
                    <footer class="card__footer">
                        <a class="button button--secondary button--sm" href="{{ route('superadmin.centers.statement.print', $st['query']) }}" target="_blank" rel="noopener"><x-ui.icon name="eye" size="16" />{{ __('sadmin_centers.statement.view') }}</a>
                        <a class="button button--secondary button--sm" href="{{ route('superadmin.centers.statement.print', $st['query'] + ['print' => 1]) }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="16" />{{ __('sadmin_billing.actions.print') }}</a>
                        <a class="button button--sm" href="{{ route('superadmin.centers.statement.pdf', $st['query']) }}"><x-ui.icon name="download" size="16" />{{ __('sadmin_billing.actions.pdf') }}</a>
                    </footer>
                @endif
            </section>
            @break

        {{-- ── Domains ────────────────────────────────────────────────── --}}
        @case('domains')
            <div class="record-grid">
                <x-ui.card :title="__('sadmin_centers.domains.title')" flush>
                    @if($can['manage'] && $tenant->slug)
                        <x-slot:actions>
                            <button class="button button--secondary button--sm" type="button" wire:click="openPanel('address')"><x-ui.icon name="edit" size="16" />{{ __('sadmin_centers.domains.change') }}</button>
                        </x-slot:actions>
                    @endif
                    @if($data['domains']->isEmpty())
                        <x-ui.empty-state compact icon="globe" :title="__('sadmin_centers.domains.none')" />
                    @else
                        <ul class="row-list">
                            @foreach($data['domains'] as $domain)
                                <li class="row-list__item" wire:key="domain-{{ $domain->id }}">
                                    <div class="row-list__body">
                                        <span class="cell-title" dir="ltr">{{ $domain->domain }}</span>
                                        <span class="cell-sub">{{ $domain->is_primary ? __('sadmin_centers.domains.primary') : __('sadmin_centers.domains.previous') }}</span>
                                    </div>
                                    <div class="cluster cluster--tight">
                                        <button class="icon-button icon-button--sm" type="button" data-copy="{{ $domain->domain }}" data-copied="{{ __('sadmin_centers.actions.copied') }}" aria-label="{{ __('sadmin_centers.actions.copy') }}" title="{{ __('sadmin_centers.actions.copy') }}"><x-ui.icon name="copy" /></button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                <x-ui.card :title="__('sadmin_centers.detail.access_links')">
                    @if($address['available'])
                        <div class="stack stack--sm">
                            @foreach(['public', 'login', 'list', 'booking'] as $key)
                                <div class="field">
                                    <label id="link-{{ $key }}">{{ __('sadmin_centers.links.'.$key) }}</label>
                                    <div class="copy-field">
                                        <code aria-labelledby="link-{{ $key }}">{{ $address['urls'][$key] }}</code>
                                        <button class="icon-button icon-button--sm" type="button" data-copy="{{ $address['urls'][$key] }}" data-copied="{{ __('sadmin_centers.actions.copied') }}" aria-label="{{ __('sadmin_centers.actions.copy') }}: {{ __('sadmin_centers.links.'.$key) }}" title="{{ __('sadmin_centers.actions.copy') }}"><x-ui.icon name="copy" /></button>
                                        <a class="icon-button icon-button--sm" href="{{ $address['urls'][$key] }}" target="_blank" rel="noopener" aria-label="{{ __('sadmin_centers.actions.open') }}: {{ __('sadmin_centers.links.'.$key) }}" title="{{ __('sadmin_centers.actions.open') }}"><x-ui.icon name="external" /></a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-ui.empty-state compact icon="globe" :title="__('sadmin_centers.detail.urls_unavailable')" :description="__('superadmin_ui.center_domain.'.$address['reason'])" />
                    @endif
                </x-ui.card>
            </div>
            @break

        {{-- ── Lifecycle ──────────────────────────────────────────────── --}}
        @case('lifecycle')
            <div class="record-grid">
                <div class="stack">
                    <x-ui.card :title="__('sadmin_centers.lifecycle.current')">
                        <div class="cluster">
                            <x-ui.status :value="$tenant->status" :label="Label::for('tenant_status', $tenant->status)" />
                            @if($tenant->suspended_at)<span class="muted">{{ __('sadmin_centers.lifecycle.since', ['date' => $dateTime($tenant->suspended_at)]) }}</span>@endif
                            @if($tenant->archived_at)<span class="muted">{{ __('sadmin_centers.lifecycle.since', ['date' => $dateTime($tenant->archived_at)]) }}</span>@endif
                        </div>
                        @if($can['manage'])
                            <div class="cluster action-row">
                                @foreach(array_diff($transitions, ['archived']) as $target)
                                    <button class="button {{ $target === 'active' ? '' : ($target === 'cancelled' ? 'button--danger-soft' : 'button--secondary') }} button--sm" type="button" wire:click="openPanel('lifecycle:{{ $target }}')"><x-ui.icon :name="$lifecycleIcon[$target]" size="16" />{{ __('sadmin_centers.lifecycle.confirm.'.$lifecycleLabel($target)) }}</button>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>

                    @if($can['manage'] && in_array('archived', $transitions, true))
                        <section class="danger-zone" aria-labelledby="remove-center">
                            <div>
                                <h2 id="remove-center">{{ __('sadmin_centers.lifecycle.remove_title') }}</h2>
                                <p>{{ __('sadmin_centers.lifecycle.remove_body') }}</p>
                            </div>
                            <button class="button button--danger" type="button" wire:click="openPanel('lifecycle:archived')"><x-ui.icon name="archive" size="16" />{{ __('sadmin_centers.lifecycle.confirm.archived') }}</button>
                        </section>
                    @endif
                </div>

                <x-ui.card :title="__('sadmin_centers.history.title')">
                    @if($data['lifecycle']->isEmpty())
                        <x-ui.empty-state compact icon="history" :title="__('sadmin_centers.history.empty')" />
                    @else
                        <ol class="timeline">
                            @foreach($data['lifecycle'] as $entry)
                                <li class="timeline__item">
                                    <span class="timeline__dot" data-tone="{{ \App\View\StatusTone::for($entry->to_status) }}" aria-hidden="true"><x-ui.icon name="dot" /></span>
                                    <div class="timeline__body">
                                        <strong>@if($entry->from_status){{ Label::for('tenant_status', $entry->from_status) }} → @endif{{ Label::for('tenant_status', $entry->to_status) }}</strong>
                                        @if($entry->reason)<p>{{ $entry->reason }}</p>@endif
                                        <div class="timeline__meta">{{ AuditAction::actor($entry->actor_label) ?? __('sadmin_centers.history.system') }} · <time datetime="{{ \Illuminate\Support\Carbon::parse($entry->occurred_at)->toIso8601String() }}">{{ $dateTime($entry->occurred_at) }}</time></div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-ui.card>
            </div>
            @break

        {{-- ── Support ────────────────────────────────────────────────── --}}
        @case('support')
            <x-ui.card :title="__('sadmin_centers.support.title')" flush>
                <x-slot:actions>
                    <a class="button button--secondary button--sm" href="{{ route('superadmin.support.index', ['center' => $tenant->id, 'status' => 'all']) }}" wire:navigate>{{ __('sadmin_centers.support.open') }}</a>
                </x-slot:actions>
                @if($data['tickets']->isEmpty())
                    <x-ui.empty-state compact icon="support" :title="__('sadmin_centers.support.none')" />
                @else
                    <ul class="row-list">
                        @foreach($data['tickets'] as $ticket)
                            <li class="row-list__item" wire:key="ticket-{{ $ticket->id }}">
                                <div class="row-list__body">
                                    <a class="cell-title" href="{{ route('superadmin.support.show', $ticket->uuid) }}" wire:navigate>{{ $ticket->subject }}</a>
                                    <span class="cell-sub"><span dir="ltr">{{ $ticket->reference }}</span> · {{ Label::for('ticket_priority', $ticket->priority) }} · {{ $ticket->last_activity_at?->diffForHumans() }}</span>
                                </div>
                                <x-ui.status :value="$ticket->status" :label="Label::for('ticket_status', $ticket->status)" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
            @break

        {{-- ── Audit ──────────────────────────────────────────────────── --}}
        @case('audit')
            <x-ui.card :title="__('sadmin_centers.history.audit_title')" flush>
                <x-slot:actions>
                    <a class="button button--secondary button--sm" href="{{ route('superadmin.audit.index', ['center' => $tenant->id]) }}" wire:navigate>{{ __('sadmin_centers.history.open_audit') }}</a>
                </x-slot:actions>
                @if($data['audit']->isEmpty())
                    <x-ui.empty-state compact icon="audit" :title="__('sadmin_centers.history.audit_empty')" />
                @else
                    <ul class="row-list">
                        @foreach($data['audit'] as $event)
                            <li class="row-list__item" wire:key="audit-{{ $event->id }}">
                                <div class="row-list__body">
                                    <span class="cell-title">{{ AuditAction::label($event->action) }}</span>
                                    <span class="cell-sub">{{ AuditAction::actor($event->actor_label, $event->actor_type) ?? __('sadmin_centers.history.system') }} · <time datetime="{{ $event->occurred_at?->toIso8601String() }}">{{ $dateTime($event->occurred_at) }}</time>@if($event->reason) · {{ \Illuminate\Support\Str::limit($event->reason, 120) }}@endif</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
            @break

        {{-- ── Overview ───────────────────────────────────────────────── --}}
        @default
            <div class="stat-grid">
                <x-ui.stat :label="__('sadmin_centers.overview.plan')" :value="$commercial['plan'] ?? '—'" :hint="$commercial ? trim(Label::for('billing_period', $commercial['cycle']).' · '.($commercial['price'] ?? '')) : null" icon="plans" />
                <x-ui.stat :label="__('sadmin_centers.overview.subscription')" :value="$commercial ? Label::for('subscription_status', $commercial['status']) : '—'"
                    :hint="$commercial ? ($commercial['trial_days'] !== null ? trans_choice('sadmin_centers.cell.trial_left', $commercial['trial_days'], ['count' => $commercial['trial_days']]) : ($commercial['period_end'] ? __('sadmin_centers.overview.renews', ['date' => $date($commercial['period_end'])]) : null)) : null" icon="subscriptions" />
                <x-ui.stat :label="__('sadmin_centers.overview.staff')" :value="number_format($data['people']['active'])" :hint="trans_choice('sadmin_centers.overview.staff_total', $data['people']['total'], ['count' => $data['people']['total']])" icon="users" />
                <x-ui.stat :label="__('sadmin_centers.overview.open_tickets')" :value="number_format($data['openTickets'])" icon="support" :tone="$data['openTickets'] > 0 ? 'warning' : null" />
                @foreach($data['outstanding'] as $due)
                    <x-ui.stat :label="__('sadmin_centers.billing.outstanding')" :value="$due['amount']" icon="wallet" tone="warning" />
                @endforeach
            </div>

            <div class="record-grid">
                <div class="stack">
                    <x-ui.card :title="__('sadmin_centers.overview.profile')">
                        @if($can['manage'])
                            <x-slot:actions>
                                <button class="button button--ghost button--sm" type="button" wire:click="openPanel('edit')"><x-ui.icon name="edit" size="16" />{{ __('sadmin_centers.actions.edit') }}</button>
                            </x-slot:actions>
                        @endif
                        <dl class="kv-grid">
                            <div><dt>{{ __('sadmin_centers.form.name') }}</dt><dd>{{ $tenant->name }}</dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.facts.slug') }}</dt><dd dir="ltr">{{ $tenant->slug ?: '—' }}</dd></div>
                            <div><dt>{{ __('sadmin_centers.form.currency') }}</dt><dd dir="ltr">{{ $currency }}@unless($tenant->currency)<span class="cell-sub">{{ __('sadmin_centers.overview.platform_default') }}</span>@endunless</dd></div>
                            <div><dt>{{ __('sadmin_centers.form.timezone') }}</dt><dd dir="ltr">{{ $tenant->timezone ?: '—' }}</dd></div>
                            <div>
                                <dt>{{ __('sadmin_centers.form.languages') }}</dt>
                                <dd>
                                    @forelse($data['languages']['enabled'] as $code)
                                        <span class="chip">{{ $supportedLocales[$code] ?? $code }}@if($code === $data['languages']['primary']) ★@endif</span>
                                    @empty
                                        —
                                    @endforelse
                                    @if($can['manage'] && $data['languages']['enabled'] !== [])
                                        <button class="text-button" type="button" wire:click="openPanel('languages')">{{ __('sadmin_centers.actions.change') }}</button>
                                    @endif
                                </dd>
                            </div>
                            <div><dt>{{ __('sadmin_centers.detail.facts.created') }}</dt><dd>{{ $date($tenant->created_at) }}</dd></div>
                        </dl>
                    </x-ui.card>

                    <x-ui.card :title="__('sadmin_centers.overview.owner')">
                        <dl class="kv-grid">
                            <div><dt>{{ __('sadmin_centers.form.contact_name') }}</dt><dd>{{ $tenant->contact_name ?: '—' }}</dd></div>
                            <div><dt>{{ __('sadmin_centers.form.contact_email') }}</dt><dd dir="ltr">@if($tenant->contact_email)<a class="cell-link" href="mailto:{{ $tenant->contact_email }}">{{ $tenant->contact_email }}</a>@else—@endif</dd></div>
                            <div><dt>{{ __('sadmin_centers.form.contact_phone') }}</dt><dd dir="ltr">{{ $tenant->contact_phone ?: '—' }}</dd></div>
                        </dl>
                        <div class="cluster action-row">
                            <button class="button button--ghost button--sm" type="button" wire:click="showTab('people')"><x-ui.icon name="users" size="16" />{{ __('sadmin_centers.overview.view_people') }}</button>
                        </div>
                    </x-ui.card>
                </div>

                <div class="stack">
                    <x-ui.card :title="__('sadmin_centers.detail.access_links')">
                        @if($address['available'])
                            <div class="stack stack--sm">
                                @foreach(['public', 'login', 'list', 'booking'] as $key)
                                    <div class="field">
                                        <label id="overview-link-{{ $key }}">{{ __('sadmin_centers.links.'.$key) }}</label>
                                        <div class="copy-field">
                                            <code aria-labelledby="overview-link-{{ $key }}">{{ $address['urls'][$key] }}</code>
                                            <button class="icon-button icon-button--sm" type="button" data-copy="{{ $address['urls'][$key] }}" data-copied="{{ __('sadmin_centers.actions.copied') }}" aria-label="{{ __('sadmin_centers.actions.copy') }}: {{ __('sadmin_centers.links.'.$key) }}" title="{{ __('sadmin_centers.actions.copy') }}"><x-ui.icon name="copy" /></button>
                                            <a class="icon-button icon-button--sm" href="{{ $address['urls'][$key] }}" target="_blank" rel="noopener" aria-label="{{ __('sadmin_centers.actions.open') }}: {{ __('sadmin_centers.links.'.$key) }}" title="{{ __('sadmin_centers.actions.open') }}"><x-ui.icon name="external" /></a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <x-ui.empty-state compact icon="globe" :title="__('sadmin_centers.detail.urls_unavailable')" :description="__('superadmin_ui.center_domain.'.$address['reason'])" />
                        @endif
                    </x-ui.card>

                    <x-ui.card :title="__('sadmin_centers.detail.facts.title')">
                        <dl class="kv-grid">
                            <div><dt>{{ __('sadmin_centers.detail.facts.provisioning') }}</dt><dd><x-ui.status :value="$tenant->provisioning_status" :label="Label::for('provisioning_status', $tenant->provisioning_status)" /></dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.facts.provisioned') }}</dt><dd>{{ $date($tenant->provisioned_at) }}</dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.facts.migration') }}</dt><dd><x-ui.status :value="$tenant->migration_status" :label="Label::for('provisioning_status', $tenant->migration_status)" /></dd></div>
                            <div><dt>{{ __('sadmin_centers.detail.facts.last_migration') }}</dt><dd>{{ $dateTime($tenant->last_migration_at) }}</dd></div>
                        </dl>
                        @if($tenant->last_migration_error)
                            <div class="notice" data-tone="danger">
                                <x-ui.icon name="alert-circle" />
                                <div><strong>{{ __('sadmin_centers.detail.facts.migration_error') }}</strong><p class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit($tenant->last_migration_error, 400) }}</p></div>
                            </div>
                        @endif
                    </x-ui.card>
                </div>
            </div>
    @endswitch
    </div>

    {{-- ── Forms ──────────────────────────────────────────────────────── --}}
    @if($panel === 'edit')
        <x-ui.drawer :title="__('sadmin_centers.form.edit_title', ['name' => $tenant->name])" submit="saveProfile">
            <section class="drawer-section">
                <h3>{{ __('sadmin_centers.form.center') }}</h3>
                <x-ui.field :label="__('sadmin_centers.form.name')" for="edit-name" name="profile.name" required>
                    <input id="edit-name" type="text" wire:model="profile.name" maxlength="190" required>
                </x-ui.field>
                <div class="form-grid">
                    <x-ui.field :label="__('sadmin_centers.form.currency')" for="edit-currency" name="profile.currency" :help="$currencyLocked ? __('sadmin_centers.form.currency_locked') : __('sadmin_centers.form.currency_help')">
                        <select id="edit-currency" wire:model="profile.currency" @disabled($currencyLocked)>
                            <option value="">{{ __('sadmin_centers.form.currency_default', ['code' => $defaultCurrency]) }}</option>
                            @foreach($currencyOptions as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('sadmin_centers.form.timezone')" for="edit-timezone" name="profile.timezone">
                        <select id="edit-timezone" wire:model="profile.timezone">
                            <option value="">—</option>
                            @foreach($timezones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>
            </section>
            <section class="drawer-section">
                <h3>{{ __('sadmin_centers.form.contact') }}</h3>
                <x-ui.field :label="__('sadmin_centers.form.contact_name')" for="edit-contact-name" name="profile.contact_name">
                    <input id="edit-contact-name" type="text" wire:model="profile.contact_name" maxlength="190" autocomplete="off">
                </x-ui.field>
                <div class="form-grid">
                    <x-ui.field :label="__('sadmin_centers.form.contact_email')" for="edit-contact-email" name="profile.contact_email">
                        <input id="edit-contact-email" type="email" dir="ltr" wire:model="profile.contact_email" maxlength="190" autocomplete="off">
                    </x-ui.field>
                    <x-ui.field :label="__('sadmin_centers.form.contact_phone')" for="edit-contact-phone" name="profile.contact_phone">
                        <input id="edit-contact-phone" type="tel" dir="ltr" wire:model="profile.contact_phone" maxlength="48" autocomplete="off">
                    </x-ui.field>
                </div>
            </section>
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="edit-reason" name="reason" required>
                <textarea id="edit-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveProfile">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif

    @if($panel === 'languages')
        <x-ui.modal :title="__('sadmin_centers.form.languages_title')" icon="languages" submit="saveLanguages">
            <fieldset class="field">
                <legend>{{ __('sadmin_centers.form.languages') }}</legend>
                <div class="check-list">
                    @foreach($supportedLocales as $code => $name)
                        <label class="choice">
                            <input type="checkbox" value="{{ $code }}" wire:model.live="locales">
                            <span>{{ $name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field :label="__('sadmin_centers.form.primary_language')" for="primary-locale" name="primaryLocale" required>
                <select id="primary-locale" wire:model="primaryLocale" required>
                    @foreach($locales as $code)
                        <option value="{{ $code }}">{{ $supportedLocales[$code] ?? $code }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="languages-reason" name="reason" required>
                <textarea id="languages-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveLanguages">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel === 'address')
        <x-ui.modal :title="__('sadmin_centers.domains.change_title')" :description="__('sadmin_centers.domains.change_body')" icon="globe" tone="warning" submit="saveAddress">
            <x-ui.field :label="__('sadmin_centers.form.slug')" for="address-slug" name="slug" required>
                <input id="address-slug" type="text" dir="ltr" wire:model.live.debounce.400ms="slug" maxlength="63" autocomplete="off" required>
            </x-ui.field>
            @if($slug !== '')<p class="field-help" dir="ltr">{{ app(\App\Kernel\Tenancy\PlatformHosts::class)->centerHost(app(\App\Kernel\Tenancy\PlatformHosts::class)->normalizeSlug($slug)) }}</p>@endif
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="address-reason" name="reason" required>
                <textarea id="address-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveAddress">{{ __('sadmin_centers.domains.change') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && str_starts_with($panel, 'lifecycle:'))
        @php $target = substr($panel, 10); @endphp
        <x-ui.modal :title="__('sadmin_centers.lifecycle.title.'.$lifecycleLabel($target), ['name' => $tenant->name])" :description="__('sadmin_centers.lifecycle.body.'.$lifecycleLabel($target))"
            :icon="$lifecycleIcon[$target] ?? 'info'" :tone="$lifecycleTone[$target] ?? null" submit="changeStatus('{{ $target }}')">
            <x-ui.field :label="__('sadmin_centers.lifecycle.reason')" for="lifecycle-reason" name="lifecycleReason" required>
                <textarea id="lifecycle-reason" wire:model="lifecycleReason" rows="3" required></textarea>
            </x-ui.field>
            @if($target === 'archived')
                <x-ui.field :label="__('sadmin_centers.lifecycle.confirm_name', ['name' => $tenant->name])" for="confirm-name" name="confirmName" required>
                    <input id="confirm-name" type="text" wire:model="confirmName" autocomplete="off" required>
                </x-ui.field>
            @endif
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('sadmin_centers.lifecycle.keep') }}</button>
                <button class="button {{ $target === 'active' ? '' : 'button--danger' }}" type="submit" wire:loading.attr="data-loading" wire:target="changeStatus">{{ __('sadmin_centers.lifecycle.confirm.'.$lifecycleLabel($target)) }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel === 'plan')
        <x-ui.modal :title="__('sadmin_centers.plan.title')" :description="__('sadmin_centers.plan.body')" icon="plans" submit="changePlan" size="lg">
            <fieldset class="field">
                <legend>{{ __('sadmin_centers.plan.plan') }}</legend>
                <div class="choice-cards">
                    @foreach($plans as $option)
                        <label class="choice-card" wire:key="plan-option-{{ $option->id }}" @class(['is-selected' => (int) $targetPlanId === $option->id])>
                            <input class="sr-only" type="radio" name="target-plan" value="{{ $option->id }}" wire:model.live="targetPlanId">
                            <span class="choice-card__title">{{ $option->name->get() }}@if($subscription && $option->id === $subscription->plan_id)<span class="badge">{{ __('sadmin_centers.plan.current') }}</span>@endif</span>
                            <span class="choice-card__meta" dir="ltr">
                                @if($planPrice($option, 'monthly')){{ $planPrice($option, 'monthly') }} / {{ __('sadmin_centers.plan.per_month') }}@endif
                                @if($planPrice($option, 'monthly') && $planPrice($option, 'yearly')) · @endif
                                @if($planPrice($option, 'yearly')){{ $planPrice($option, 'yearly') }} / {{ __('sadmin_centers.plan.per_year') }}@endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @php $chosen = $plans->firstWhere('id', (int) $targetPlanId); @endphp
            <fieldset class="field">
                <legend>{{ __('sadmin_centers.plan.cycle') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach(['monthly', 'yearly'] as $option)
                        <label @class(['is-active' => $cycle === $option, 'is-disabled' => $chosen && ! $chosen->offers($option)])>
                            <input class="sr-only" type="radio" name="plan-cycle" value="{{ $option }}" wire:model.live="cycle" @disabled($chosen && ! $chosen->offers($option))>{{ Label::for('billing_period', $option) }}
                        </label>
                    @endforeach
                </div>
                @if($chosen && $planPrice($chosen, $cycle))
                    <p class="field-help">{{ __('sadmin_centers.plan.new_price', ['price' => $planPrice($chosen, $cycle)]) }}</p>
                @endif
            </fieldset>
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="plan-reason" name="reason" required>
                <textarea id="plan-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="changePlan">{{ __('sadmin_centers.plan.apply') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel === 'activate')
        <x-ui.modal :title="__('sadmin_centers.activate.title')" :description="__('sadmin_centers.activate.body')" icon="check-circle" submit="activate">
            <fieldset class="field">
                <legend>{{ __('sadmin_centers.plan.cycle') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach(['monthly', 'yearly'] as $option)
                        <label @class(['is-active' => $cycle === $option, 'is-disabled' => $subscription?->plan && ! $subscription->plan->offers($option)])>
                            <input class="sr-only" type="radio" name="activate-cycle" value="{{ $option }}" wire:model.live="cycle" @disabled($subscription?->plan && ! $subscription->plan->offers($option))>{{ Label::for('billing_period', $option) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field :label="__('sadmin_centers.activate.starts')" for="activate-start" name="startsAt" required>
                <input id="activate-start" type="date" wire:model="startsAt" required>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="activate-reason" name="reason" required>
                <textarea id="activate-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="activate">{{ __('sadmin_centers.actions.activate') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel === 'trial')
        <x-ui.modal :title="__('sadmin_centers.trial.title')" icon="clock" submit="extendTrial">
            <x-ui.field :label="__('sadmin_centers.trial.until')" for="trial-until" name="trialUntil" required>
                <input id="trial-until" type="date" wire:model="trialUntil" min="{{ now()->addDay()->format('Y-m-d') }}" required>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="trial-reason" name="reason" required>
                <textarea id="trial-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="extendTrial">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel === 'renewal')
        <x-ui.modal :title="__('sadmin_centers.renewal.title')" icon="calendar" submit="setRenewal">
            <x-ui.field :label="__('sadmin_centers.renewal.date')" for="renewal-date" name="renewalDate" required>
                <input id="renewal-date" type="date" wire:model="renewalDate" min="{{ now()->addDay()->format('Y-m-d') }}" required>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="renewal-reason" name="reason" required>
                <textarea id="renewal-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="setRenewal">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel === 'schedule')
        <x-ui.modal :title="__('sadmin_centers.schedule.form_title')" icon="calendar" submit="scheduleChange">
            <x-ui.field :label="__('sadmin_centers.schedule.target')" for="schedule-plan" name="targetPlanId" required>
                <select id="schedule-plan" wire:model="targetPlanId" required>
                    <option value="">{{ __('sadmin_centers.schedule.choose_plan') }}</option>
                    @foreach($plans as $option)
                        <option value="{{ $option->id }}" @disabled($subscription && $option->id === $subscription->plan_id)>{{ $option->name?->get() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.schedule.effective')" for="schedule-at" name="effectiveAt" required>
                <input id="schedule-at" type="datetime-local" wire:model="effectiveAt" min="{{ now()->format('Y-m-d\TH:i') }}" required>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.schedule.reason')" for="schedule-reason" name="scheduleReason" required>
                <textarea id="schedule-reason" wire:model="scheduleReason" rows="3" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="scheduleChange">{{ __('sadmin_centers.schedule.submit') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && str_starts_with($panel, 'entitlement:'))
        <x-ui.modal :title="__('sadmin_centers.entitlements.form_title', ['capability' => __('platform_labels.entitlement.'.$entitlement)])" icon="entitlements" submit="setEntitlement">
            <fieldset class="field">
                <legend>{{ __('sadmin_centers.entitlements.mode') }}</legend>
                <div class="segmented" role="radiogroup">
                    @foreach(['grant', 'revoke'] as $option)
                        <label @class(['is-active' => $mode === $option])>
                            <input class="sr-only" type="radio" name="entitlement-mode" value="{{ $option }}" wire:model.live="mode">{{ __('sadmin_centers.entitlements.'.$option) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <x-ui.field :label="__('sadmin_centers.entitlements.expiry')" for="entitlement-expiry" name="expiresAt">
                <input id="entitlement-expiry" type="datetime-local" wire:model="expiresAt" min="{{ now()->format('Y-m-d\TH:i') }}">
            </x-ui.field>
            <x-ui.field :label="__('sadmin_centers.lifecycle.reason')" for="entitlement-reason" name="entitlementReason" required>
                <textarea id="entitlement-reason" wire:model="entitlementReason" rows="3" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="setEntitlement">{{ __('sadmin_centers.entitlements.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && str_starts_with($panel, 'entitlement-reset:'))
        @php
            $overrideId = (int) substr($panel, 18);
            $resetKey = \App\Kernel\SaaS\Models\TenantEntitlementOverride::query()->where('tenant_id', $tenant->id)->whereKey($overrideId)->value('entitlement');
        @endphp
        <x-ui.modal :title="__('sadmin_centers.entitlements.reset_title', ['capability' => __('platform_labels.entitlement.'.$resetKey)])" :description="__('sadmin_centers.entitlements.reset_body')" icon="reset" tone="warning" submit="clearEntitlement({{ $overrideId }})">
            <x-ui.field :label="__('sadmin_centers.lifecycle.reason')" for="entitlement-reset-reason" name="entitlementReason" required>
                <textarea id="entitlement-reset-reason" wire:model="entitlementReason" rows="3" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="clearEntitlement">{{ __('sadmin_centers.entitlements.reset') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && str_starts_with($panel, 'usage:'))
        <x-ui.modal :title="__('sadmin_centers.usage.form_title', ['resource' => Label::for('usage_resource', $usageResource)])" icon="usage" submit="saveAllowance">
            <label class="choice">
                <input type="checkbox" wire:model.live="usageUnlimited">
                <span>{{ __('sadmin_centers.usage.unlimited_field') }}</span>
            </label>
            @unless($usageUnlimited)
                <x-ui.field :label="__('sadmin_centers.usage.allowance_field')" for="usage-allowance" name="usageAllowance" required>
                    <input id="usage-allowance" type="number" min="0" step="1" inputmode="numeric" wire:model="usageAllowance" required>
                </x-ui.field>
            @endunless
            <label class="choice">
                <input type="checkbox" wire:model="usageEnforce">
                <span>{{ __('sadmin_centers.usage.enforce') }}<small class="field-help">{{ __('sadmin_centers.usage.enforce_help') }}</small></span>
            </label>
            <x-ui.field :label="__('sadmin_centers.lifecycle.reason')" for="usage-reason" name="usageReason" required>
                <textarea id="usage-reason" wire:model="usageReason" rows="3" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveAllowance">{{ __('sadmin_centers.usage.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && str_starts_with($panel, 'usage-reset:'))
        @php
            $limitId = (int) substr($panel, 12);
            $resetResource = \App\Kernel\Usage\Models\TenantLimitOverride::query()->where('tenant_id', $tenant->id)->whereKey($limitId)->value('resource');
        @endphp
        <x-ui.modal :title="__('sadmin_centers.usage.reset_title', ['resource' => Label::for('usage_resource', $resetResource)])" :description="__('sadmin_centers.usage.reset_body')" icon="reset" tone="warning" submit="clearAllowance({{ $limitId }})">
            <x-ui.field :label="__('sadmin_centers.lifecycle.reason')" for="usage-reset-reason" name="usageReason" required>
                <textarea id="usage-reset-reason" wire:model="usageReason" rows="3" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="clearAllowance">{{ __('sadmin_centers.usage.reset') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && (str_starts_with($panel, 'person-block:') || str_starts_with($panel, 'person-unblock:')))
        @php
            [$personAction, $personUuid] = explode(':', $panel, 2);
            $blocking = $personAction === 'person-block';
        @endphp
        <x-ui.modal :title="__('sadmin_centers.people.'.($blocking ? 'block_title' : 'reactivate_title'))" :description="__('sadmin_centers.people.'.($blocking ? 'block_body' : 'reactivate_body'))" :icon="$blocking ? 'user-x' : 'user-check'" :tone="$blocking ? 'danger' : null" submit="setPersonActive('{{ $personUuid }}', {{ $blocking ? 'false' : 'true' }})">
            <x-ui.field :label="__('sadmin_centers.form.reason')" for="person-reason" name="reason" required>
                <textarea id="person-reason" wire:model="reason" rows="2" required></textarea>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button {{ $blocking ? 'button--danger' : '' }}" type="submit" wire:loading.attr="data-loading" wire:target="setPersonActive">{{ __('sadmin_centers.people.'.($blocking ? 'block' : 'reactivate')) }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if($panel && str_starts_with($panel, 'person-link:'))
        @php $personUuid = substr($panel, 12); @endphp
        <x-ui.modal :title="__('sadmin_centers.people.link_title')" :description="__('sadmin_centers.people.link_body')" icon="mail" submit="sendAccessLink('{{ $personUuid }}')">
            @error('reason')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="sendAccessLink">{{ __('sadmin_centers.people.send_link') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
