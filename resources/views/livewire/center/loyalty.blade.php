{{--
    Loyalty: what is switched on, the rules and their history, the tiers, and
    who holds points.

    docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§6, 9, 22, 27. Zero switches a
    rule off. A change applies to what happens next; points already earned
    keep the expiry they were earned with. Every value arrives prepared by
    Loyalty::render() / LoyaltyPresenter.
--}}
<div class="stack benefits-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.loyalty')" />

    @if($offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($error !== '' && ! $showTierForm)
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
    @endif
    @if($saved !== '')
        <div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>
    @endif

    <div class="stat-grid benefits-kpis">
        <x-ui.stat icon="users" :label="__('manager_benefits.loyalty.kpi_members')" :value="number_format($totals['members'])" />
        <x-ui.stat icon="coins" :label="__('manager_benefits.loyalty.kpi_outstanding')" :value="number_format($totals['outstanding_points'])" />
        <x-ui.stat icon="star" :label="__('manager_benefits.loyalty.kpi_lifetime')" :value="number_format($totals['lifetime_points'])" />
    </div>

    <div class="record-grid">
        <div class="stack">
            <form class="card card--flush" wire:submit="save" aria-labelledby="loyalty-rules-title">
                <header class="card__header">
                    <div class="cluster cluster--tight">
                        <h2 id="loyalty-rules-title">{{ __('manager_benefits.loyalty.rules') }}</h2>
                        <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_benefits.loyalty.rules_hint') }}" aria-label="{{ __('manager_benefits.loyalty.rules_hint') }}"><x-ui.icon name="info" size="16" /></span>
                    </div>
                </header>
                <div class="card__body">
                    <div class="rule-grid">
                        <fieldset class="rule-card" @disabled(! $canManage)>
                            <legend><x-ui.icon name="coins" size="16" />{{ __('manager_benefits.loyalty.earn_spend') }}</legend>
                            <x-ui.field :label="__('manager_benefits.loyalty.points')" for="spend-points" name="spendPoints">
                                <input id="spend-points" type="number" min="0" max="10000" inputmode="numeric" wire:model="spendPoints">
                            </x-ui.field>
                            <x-ui.field :label="__('manager_benefits.loyalty.per_amount', ['currency' => $currency])" for="spend-unit" name="spendUnit">
                                <input id="spend-unit" type="text" dir="ltr" inputmode="decimal" wire:model="spendUnit" placeholder="1000">
                            </x-ui.field>
                            <x-ui.field :label="__('manager_benefits.loyalty.min_spend', ['currency' => $currency])" for="min-spend" name="minSpend" :help="__('manager_benefits.loyalty.min_spend_help')">
                                <input id="min-spend" type="text" dir="ltr" inputmode="decimal" wire:model="minSpend" placeholder="0">
                            </x-ui.field>
                        </fieldset>

                        <fieldset class="rule-card" @disabled(! $canManage)>
                            <legend><x-ui.icon name="calendar" size="16" />{{ __('manager_benefits.loyalty.earn_visits') }}</legend>
                            <x-ui.field :label="__('manager_benefits.loyalty.visit_points')" for="visit-points" name="visitPoints" :help="__('manager_benefits.loyalty.visit_points_help')">
                                <input id="visit-points" type="number" min="0" max="10000" inputmode="numeric" wire:model="visitPoints">
                            </x-ui.field>
                        </fieldset>

                        <fieldset class="rule-card" @disabled(! $canManage)>
                            <legend><x-ui.icon name="gift" size="16" />{{ __('manager_benefits.loyalty.redeeming') }}</legend>
                            <x-ui.field :label="__('manager_benefits.loyalty.point_value', ['currency' => $currency])" for="point-value" name="pointValue" :help="__('manager_benefits.loyalty.point_value_help')">
                                <input id="point-value" type="text" dir="ltr" inputmode="decimal" wire:model="pointValue" placeholder="100">
                            </x-ui.field>
                            <x-ui.field :label="__('manager_benefits.loyalty.min_redeem')" for="min-redeem" name="minRedeem">
                                <input id="min-redeem" type="number" min="0" inputmode="numeric" wire:model="minRedeem">
                            </x-ui.field>
                        </fieldset>

                        <fieldset class="rule-card" @disabled(! $canManage)>
                            <legend><x-ui.icon name="clock" size="16" />{{ __('manager_benefits.loyalty.expiry') }}</legend>
                            <x-ui.field :label="__('manager_benefits.loyalty.expiry_days')" for="expiry-days" name="expiryDays" :help="__('manager_benefits.loyalty.expiry_help')">
                                <input id="expiry-days" type="number" min="1" max="3650" inputmode="numeric" wire:model="expiryDays">
                            </x-ui.field>
                        </fieldset>
                    </div>
                </div>
                @if($canManage)
                    <footer class="card__footer">
                        <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_benefits.loyalty.save_rules') }}</button>
                    </footer>
                @endif
            </form>

            <x-ui.card :title="__('manager_benefits.loyalty.tiers')" flush>
                <x-slot:actions>
                    <label class="choice choice--switch benefits-toggle"><input class="switch" type="checkbox" role="switch" wire:model.live="showArchivedTiers"><span>{{ __('manager_benefits.common.show_archived') }}</span></label>
                    @if($canManage)
                        <button class="button button--secondary button--sm" type="button" wire:click="openTierForm"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.loyalty.add_tier') }}</button>
                    @endif
                </x-slot:actions>
                @if($tiers === [])
                    <x-ui.empty-state compact icon="star" :title="__('manager_benefits.loyalty.no_tiers')" />
                @else
                    <ul class="row-list" wire:loading.class="is-refreshing" wire:target="showArchivedTiers">
                        @foreach($tiers as $tier)
                            <li class="row-list__item" wire:key="tier-{{ $tier['uuid'] }}" @if($tier['archived']) data-muted="true" @endif>
                                <span class="row-list__icon" aria-hidden="true"><x-ui.icon name="star" /></span>
                                <div class="row-list__body">
                                    <span class="cell-title">{{ $tier['name'] }} @if($tier['archived'])<span class="chip chip--muted">{{ __('manager_benefits.common.archived') }}</span>@endif</span>
                                    @if($tier['benefit_note'])<span class="cell-sub">{{ $tier['benefit_note'] }}</span>@endif
                                </div>
                                <span class="tabular benefits-threshold">{{ __('manager_benefits.loyalty.from_points', ['n' => number_format((int) $tier['threshold_points'])]) }}</span>
                                @if($canManage)
                                    <span class="cluster cluster--tight">
                                        @if($tier['archived'])
                                            <button class="button button--ghost button--sm" type="button" wire:click="restoreTier('{{ $tier['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('ui.actions.restore') }}</button>
                                        @else
                                            <button class="icon-button icon-button--sm" type="button" wire:click="editTier('{{ $tier['uuid'] }}')" aria-label="{{ __('manager_benefits.loyalty.edit_tier', ['name' => $tier['name']]) }}" title="{{ __('ui.actions.edit') }}"><x-ui.icon name="edit" /></button>
                                            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="archiveTier('{{ $tier['uuid'] }}')" wire:confirm="{{ __('manager_benefits.loyalty.archive_tier_body', ['name' => $tier['name']]) }}" data-confirm-title="{{ __('manager_benefits.loyalty.archive_tier_title') }}" data-confirm-label="{{ __('ui.actions.archive') }}" data-confirm-tone="danger" aria-label="{{ __('manager_benefits.loyalty.archive_tier_title') }}" title="{{ __('ui.actions.archive') }}"><x-ui.icon name="archive" /></button>
                                        @endif
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <aside class="record-aside">
            <x-ui.card :title="__('manager_benefits.loyalty.status')">
                @if($program === null)
                    <x-ui.empty-state compact icon="loyalty" :title="__('manager_benefits.loyalty.not_configured')" />
                @else
                    <ul class="benefits-switches">
                        <li>
                            <x-ui.status :value="$program['earns_on_spend'] ? 'active' : 'inactive'" :label="$program['earns_on_spend'] ? __('manager_benefits.common.on') : __('manager_benefits.common.off')" />
                            <div>
                                <strong>{{ __('manager_benefits.loyalty.earn_spend') }}</strong>
                                @if($program['earns_on_spend'])<span class="muted">{{ __('manager_benefits.loyalty.spend_summary', ['n' => number_format((int) $program['spend_points']), 'amount' => $program['spend_unit']['formatted']]) }}</span>@endif
                            </div>
                        </li>
                        <li>
                            <x-ui.status :value="$program['earns_on_visits'] ? 'active' : 'inactive'" :label="$program['earns_on_visits'] ? __('manager_benefits.common.on') : __('manager_benefits.common.off')" />
                            <div>
                                <strong>{{ __('manager_benefits.loyalty.earn_visits') }}</strong>
                                @if($program['earns_on_visits'])<span class="muted">{{ __('manager_benefits.loyalty.visit_summary', ['n' => number_format((int) $program['visit_points'])]) }}</span>@endif
                            </div>
                        </li>
                        <li>
                            <x-ui.status :value="$program['allows_redemption'] ? 'active' : 'inactive'" :label="$program['allows_redemption'] ? __('manager_benefits.common.on') : __('manager_benefits.common.off')" />
                            <div>
                                <strong>{{ __('manager_benefits.loyalty.redeeming') }}</strong>
                                @if($program['allows_redemption'])<span class="muted">{{ __('manager_benefits.loyalty.redeem_summary', ['amount' => $program['point_value']['formatted'], 'n' => number_format((int) $program['min_redeem_points'])]) }}</span>@endif
                            </div>
                        </li>
                        <li>
                            <x-ui.status tone="neutral" :dot="false" :label="$program['expiry_days'] === null ? __('manager_benefits.loyalty.never_expire') : __('manager_benefits.loyalty.expire_after', ['n' => $program['expiry_days']])" />
                            <div><strong>{{ __('manager_benefits.loyalty.expiry') }}</strong></div>
                        </li>
                    </ul>
                    @if($program['updated_by'] || $programUpdated)
                        <p class="field-help">{{ __('manager_benefits.loyalty.changed_by', ['name' => $program['updated_by'] ?? '—', 'date' => $programUpdated ?? '—']) }}</p>
                    @endif
                @endif
            </x-ui.card>

            <x-ui.card :title="__('manager_benefits.loyalty.history')">
                @if($versions === [])
                    <p class="muted">{{ __('manager_benefits.loyalty.no_history') }}</p>
                @else
                    <ol class="timeline">
                        @foreach($versions as $version)
                            <li class="timeline__item" wire:key="version-{{ $version['uuid'] }}">
                                <span class="timeline__dot" @if($loop->first) data-tone="primary" @endif aria-hidden="true"><x-ui.icon name="history" /></span>
                                <div class="timeline__body">
                                    <strong>{{ $version['when'] }}</strong>
                                    <p>
                                        {{ $version['spend_points'] > 0 ? __('manager_benefits.loyalty.spend_summary', ['n' => number_format((int) $version['spend_points']), 'amount' => $version['spend_unit']['formatted']]) : __('manager_benefits.loyalty.spend_off') }}
                                        · {{ $version['visit_points'] > 0 ? __('manager_benefits.loyalty.visit_summary', ['n' => number_format((int) $version['visit_points'])]) : __('manager_benefits.loyalty.visits_off') }}
                                        · {{ $version['expiry_days'] === null ? __('manager_benefits.loyalty.never_expire') : __('manager_benefits.loyalty.expire_after', ['n' => $version['expiry_days']]) }}
                                    </p>
                                    @if($version['changed_by'])<span class="timeline__meta">{{ $version['changed_by'] }}</span>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </aside>
    </div>

    <livewire:center.benefits.loyalty-members />

    @if($canManage && $showTierForm)
        <x-ui.modal :title="$editingTier ? __('manager_benefits.loyalty.edit_tier_title') : __('manager_benefits.loyalty.add_tier')" icon="star" submit="saveTier" close="closeTierForm">
            @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
            <x-ui.lang-tabs id="tier-text" :locales="$locales" :primary="$primaryLocale" :values="['tierName' => $tierName, 'tierNote' => $tierNote]" :fields="[
                ['name' => 'tierName', 'label' => __('manager_benefits.loyalty.tier_name'), 'max' => 80, 'required' => true],
                ['name' => 'tierNote', 'label' => __('manager_benefits.loyalty.tier_note'), 'max' => 190],
            ]" />
            <x-ui.field :label="__('manager_benefits.loyalty.threshold')" for="tier-threshold" name="tierThreshold" required :help="__('manager_benefits.loyalty.threshold_help')">
                <input id="tier-threshold" type="number" min="0" inputmode="numeric" wire:model="tierThreshold">
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeTierForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveTier">{{ $editingTier ? __('ui.actions.save_changes') : __('manager_benefits.loyalty.add_tier') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
