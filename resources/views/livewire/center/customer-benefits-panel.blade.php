{{--
    One customer's points, memberships and packages — for staff.

    docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§19, 21, 22, 27. Read-only
    history; a hand adjustment or a cancellation always carries a reason.
    Every label and date arrives prepared by CustomerBenefitsPanel.
--}}
<div class="stack customer-benefits">
    @if($error !== '')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
    @endif
    @if($saved !== '')
        <div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>
    @endif

    @if($nothingVisible)
        <x-ui.card><x-ui.empty-state compact icon="lock" :title="__('manager_customers.panels.denied_title')" /></x-ui.card>
    @endif

    {{-- A module the center does not own: the compact plan notice, over its history or alone. --}}
    @foreach($locks as $feature => $lock)
        <x-manager.feature-locked :offer="$lock['offer']" compact :history="$lock['history']" wire:key="lock-{{ $feature }}" />
    @endforeach

    {{-- ── Points ──────────────────────────────────────────────────────── --}}
    @if($showLoyalty && $loyalty !== null)
        <div class="record-grid">
            <x-ui.card :title="__('manager_benefits.panel.points_title')" flush>
                <div class="stat-strip benefits-strip">
                    <div class="stat-strip__item">
                        <span>{{ __('manager_benefits.panel.available') }}</span>
                        <strong>{{ number_format((int) $loyalty['available_points']) }}</strong>
                        @if($loyalty['available_value'])<small class="muted" dir="ltr">{{ __('manager_benefits.panel.worth', ['amount' => $loyalty['available_value']['formatted']]) }}</small>@endif
                    </div>
                    <div class="stat-strip__item">
                        <span>{{ __('manager_benefits.panel.tier') }}</span>
                        <strong class="benefits-strip__text">{{ $loyalty['tier']['name'] ?? __('manager_benefits.panel.no_tier') }}</strong>
                    </div>
                    <div class="stat-strip__item">
                        <span>{{ __('manager_benefits.panel.lifetime') }}</span>
                        <strong>{{ number_format((int) $loyalty['lifetime_points']) }}</strong>
                    </div>
                    @if($loyalty['unrecovered_points'] > 0)
                        <div class="stat-strip__item" title="{{ __('manager_benefits.panel.unrecovered_hint') }}">
                            <span>{{ __('manager_benefits.panel.unrecovered') }}</span>
                            <strong class="text-warning">{{ number_format((int) $loyalty['unrecovered_points']) }}</strong>
                        </div>
                    @endif
                    @if($loyalty['expired_unwritten'] > 0)
                        <div class="stat-strip__item">
                            <span>{{ __('manager_benefits.panel.expired') }}</span>
                            <strong class="muted">{{ number_format((int) $loyalty['expired_unwritten']) }}</strong>
                        </div>
                    @endif
                </div>

                @if($loyalty['recent'] === [])
                    <x-ui.empty-state compact icon="coins" :title="__('manager_benefits.panel.no_movements')" />
                @else
                    <div class="table-shell table-shell--stack">
                        <table>
                            <caption class="sr-only">{{ __('manager_benefits.panel.history') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('manager_benefits.panel.when') }}</th>
                                    <th scope="col">{{ __('manager_benefits.panel.what') }}</th>
                                    <th scope="col" class="numeric">{{ __('manager_benefits.panel.points') }}</th>
                                    <th scope="col">{{ __('manager_benefits.panel.reason') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($loyalty['recent'] as $row)
                                    <tr wire:key="points-{{ $row['uuid'] }}">
                                        <td data-label="{{ __('manager_benefits.panel.when') }}">{{ $row['when'] }}</td>
                                        <td data-label="{{ __('manager_benefits.panel.what') }}" data-primary><span class="cell-title">{{ $row['kind_label'] }}</span></td>
                                        <td data-label="{{ __('manager_benefits.panel.points') }}" class="numeric">
                                            <strong class="tabular {{ $row['direction'] === 'in' ? 'text-success' : 'text-danger' }}" dir="ltr">{{ $row['signed'] }}</strong>
                                            @if($row['unrecovered_points'] > 0)<span class="cell-sub">{{ __('manager_benefits.panel.row_unrecovered', ['n' => number_format((int) $row['unrecovered_points'])]) }}</span>@endif
                                        </td>
                                        <td data-label="{{ __('manager_benefits.panel.reason') }}">
                                            {{ $row['reason'] ?? '—' }}
                                            @if($row['by'] !== null)<span class="cell-sub">{{ $row['by'] }}</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            <aside class="record-aside">
                @if($canAdjust)
                    <form class="card" wire:submit="adjust">
                        <header class="card__header"><div><h2>{{ __('manager_benefits.panel.adjust_title') }}</h2></div></header>
                        <div class="card__body stack stack--sm">
                            <div class="segmented" role="group" aria-label="{{ __('manager_benefits.panel.direction') }}">
                                <button type="button" wire:click="$set('direction', 'in')" aria-pressed="{{ $direction === 'in' ? 'true' : 'false' }}"><x-ui.icon name="plus" size="14" />{{ __('manager_benefits.panel.add_points') }}</button>
                                <button type="button" wire:click="$set('direction', 'out')" aria-pressed="{{ $direction === 'out' ? 'true' : 'false' }}">{{ __('manager_benefits.panel.take_points') }}</button>
                            </div>
                            <x-ui.field :label="__('manager_benefits.panel.points')" for="adjust-points" name="points" required>
                                <input id="adjust-points" type="number" min="1" inputmode="numeric" wire:model="points">
                            </x-ui.field>
                            <x-ui.field :label="__('manager_benefits.panel.reason')" for="adjust-reason" name="reason" required :help="__('manager_benefits.panel.reason_help')">
                                <input id="adjust-reason" type="text" maxlength="190" wire:model="reason" autocomplete="off">
                            </x-ui.field>
                        </div>
                        <footer class="card__footer">
                            <button class="button button--sm" type="submit" wire:loading.attr="data-loading" wire:target="adjust">{{ __('manager_benefits.panel.adjust') }}</button>
                        </footer>
                    </form>
                @elseif(! $loyaltyOn && ! isset($locks['loyalty']))
                    <div class="notice" data-tone="info" role="status"><x-ui.icon name="lock" /><p>{{ __('manager_benefits.panel.loyalty_paused') }}</p></div>
                @endif
            </aside>
        </div>
    @endif

    {{-- ── Memberships ─────────────────────────────────────────────────── --}}
    @if($showPlans && $memberships !== null)
        <x-ui.card :title="__('manager_benefits.panel.memberships_title')">
            @if($memberships === [])
                <x-ui.empty-state compact icon="memberships" :title="__('manager_benefits.panel.no_memberships')" />
            @else
                <div class="benefit-cards">
                    @foreach($memberships as $membership)
                        <article class="benefit-card" wire:key="membership-{{ $membership['uuid'] }}" data-state="{{ $membership['state'] }}">
                            <header class="benefit-card__head">
                                <h3>{{ $membership['name'] }}</h3>
                                <x-ui.status :value="$membership['state']" :label="$membership['state_label']" />
                            </header>
                            <p class="benefit-card__dates">{{ __('manager_benefits.panel.valid_between', ['from' => $membership['first_day_label'], 'to' => $membership['last_day_label']]) }}</p>
                            <ul class="benefit-card__lines">
                                @foreach($membership['benefits'] as $benefit)
                                    <li>
                                        <span>{{ $benefit['all_services'] ? __('manager_benefits.panel.every_service') : $benefit['service_name'] }}</span>
                                        <strong dir="ltr">{{ $benefit['percent'] !== null ? $benefit['percent'].'%' : ($benefit['amount']['formatted'] ?? '') }}</strong>
                                        @if($benefit['uses_left'] !== null)<span class="muted">{{ __('manager_benefits.panel.left', ['n' => $benefit['uses_left'], 'm' => $benefit['uses_limit']]) }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                            @if($membership['cancel_reason'] !== null)
                                <p class="benefit-card__note"><x-ui.icon name="x-circle" size="14" />{{ __('manager_benefits.panel.cancelled_by', ['by' => $membership['cancelled_by'] ?? '—', 'reason' => $membership['cancel_reason']]) }}</p>
                            @elseif($canCancelMembership && $membership['cancellable'])
                                @include('livewire.center.benefits.cancel-form', ['key' => 'membership:'.$membership['uuid']])
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- ── Packages ────────────────────────────────────────────────────── --}}
    @if($showPlans && $packages !== null)
        <x-ui.card :title="__('manager_benefits.panel.packages_title')">
            @if($packages === [])
                <x-ui.empty-state compact icon="packages" :title="__('manager_benefits.panel.no_packages')" />
            @else
                <div class="benefit-cards">
                    @foreach($packages as $package)
                        <article class="benefit-card" wire:key="package-{{ $package['uuid'] }}" data-state="{{ $package['state'] }}">
                            <header class="benefit-card__head">
                                <h3>{{ $package['name'] }}</h3>
                                <x-ui.status :value="$package['state']" :label="$package['state_label']" />
                            </header>
                            <p class="benefit-card__dates">{{ __('manager_benefits.panel.valid_until', ['date' => $package['last_day_label']]) }}</p>
                            <ul class="benefit-card__lines">
                                @foreach($package['items'] as $item)
                                    <li>
                                        <span>{{ $item['name'] }}@if($item['variation'] !== null) · {{ $item['variation'] }}@endif</span>
                                        <span class="muted">{{ __('manager_benefits.panel.left', ['n' => $item['left'], 'm' => $item['allocated']]) }}</span>
                                        <progress class="benefit-card__meter" max="{{ max(1, (int) $item['allocated']) }}" value="{{ (int) $item['left'] }}" aria-hidden="true"></progress>
                                    </li>
                                @endforeach
                            </ul>
                            @if($package['cancel_reason'] !== null)
                                <p class="benefit-card__note"><x-ui.icon name="x-circle" size="14" />{{ __('manager_benefits.panel.cancelled_by', ['by' => $package['cancelled_by'] ?? '—', 'reason' => $package['cancel_reason']]) }}</p>
                            @elseif($canCancelPackage && $package['cancellable'])
                                @include('livewire.center.benefits.cancel-form', ['key' => 'package:'.$package['uuid']])
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
