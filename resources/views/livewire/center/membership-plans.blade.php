{{--
    Membership plans: what a membership gives, for how long, at what price —
    and who holds one.

    docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§10, 12, 22, 27. Sold at the till;
    editing a plan changes what is sold next, never what a member bought.
--}}
<div class="stack benefits-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.memberships')">
        @if($canManage)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openForm"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.plans.add') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if($offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($error !== '' && ! $showForm)
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
    @endif
    @if($saved !== '')
        <div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>
    @endif

    <div class="segmented" role="group" aria-label="{{ __('manager_benefits.common.status') }}">
        <button type="button" wire:click="setView('active')" aria-pressed="{{ $view === 'active' ? 'true' : 'false' }}">{{ __('manager_benefits.common.on_sale') }}<span class="segmented__count">{{ $activeCount }}</span></button>
        <button type="button" wire:click="setView('archived')" aria-pressed="{{ $view === 'archived' ? 'true' : 'false' }}">{{ __('manager_benefits.common.archived') }}<span class="segmented__count">{{ $archivedCount }}</span></button>
    </div>

    <div wire:loading.class="is-refreshing" wire:target="setView">
        @if($plans === [])
            <x-ui.card>
                @if($view === 'archived')
                    <x-ui.empty-state compact icon="archive" :title="__('manager_benefits.plans.no_archived')" />
                @else
                    <x-ui.empty-state icon="memberships" :title="__('manager_benefits.plans.empty_title')">
                        @if($canManage)<button class="button button--sm" type="button" wire:click="openForm"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.plans.add') }}</button>@endif
                    </x-ui.empty-state>
                @endif
            </x-ui.card>
        @else
            <div class="plan-grid">
                @foreach($plans as $plan)
                    <article class="plan-card" wire:key="plan-{{ $plan['uuid'] }}" @if($plan['archived']) data-archived @endif>
                        <header class="plan-card__head">
                            <h2>{{ $plan['name'] }}</h2>
                            <x-ui.status tone="neutral" :label="trans_choice('manager_benefits.plans.days', (int) $plan['duration_days'], ['n' => $plan['duration_days']])" :dot="false" />
                        </header>
                        <p class="plan-card__price"><span dir="ltr" class="tabular">{{ $plan['price']['formatted'] }}</span></p>
                        <ul class="plan-card__facts">
                            @foreach($plan['benefits'] as $benefit)
                                <li>
                                    <x-ui.icon name="percent" size="16" />
                                    <span>
                                        {{ $benefit['service_name'] ?? __('manager_benefits.panel.every_service') }}:
                                        <strong dir="ltr">{{ $benefit['percent'] !== null ? $benefit['percent'].'%' : ($benefit['amount']['formatted'] ?? '') }}</strong>
                                        <span class="muted"> — {{ $benefit['uses_per_term'] !== null ? trans_choice('manager_benefits.plans.uses_per_term', (int) $benefit['uses_per_term'], ['n' => $benefit['uses_per_term']]) : __('manager_benefits.plans.unlimited') }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="benefits-holders"><x-ui.icon name="users" size="14" />{{ trans_choice('manager_benefits.plans.members_count', (int) $plan['members'], ['n' => number_format((int) $plan['members'])]) }}</p>
                        @if($canManage)
                            <footer class="plan-card__foot cluster cluster--tight">
                                @if($plan['archived'])
                                    <button class="button button--secondary button--sm" type="button" wire:click="restore('{{ $plan['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('manager_benefits.plans.put_back') }}</button>
                                @else
                                    <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="archive('{{ $plan['uuid'] }}')" wire:confirm="{{ __('manager_benefits.plans.archive_body', ['name' => $plan['name']]) }}" data-confirm-title="{{ __('manager_benefits.plans.archive_title') }}" data-confirm-label="{{ __('ui.actions.archive') }}" data-confirm-tone="danger"><x-ui.icon name="archive" size="16" />{{ __('ui.actions.archive') }}</button>
                                    <button class="button button--secondary button--sm" type="button" wire:click="edit('{{ $plan['uuid'] }}')"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                                @endif
                            </footer>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    <livewire:center.benefits.membership-members />

    @if($canManage && $showForm)
        <x-ui.drawer :title="$editing ? __('manager_benefits.plans.edit_title') : __('manager_benefits.plans.add')" :description="$editing ? __('manager_benefits.plans.edit_hint') : null" close="closeForm" submit="save" size="lg">
            <div class="stack">
                @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
                <x-ui.lang-tabs id="membership-name" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                    ['name' => 'name', 'label' => __('manager_benefits.common.name'), 'max' => 120, 'required' => true],
                ]" />
                <div class="form-grid form-grid--3">
                    <x-ui.field :label="__('manager_benefits.common.price', ['currency' => $currency])" for="membership-price" name="price" required>
                        <input id="membership-price" type="text" dir="ltr" inputmode="decimal" wire:model="price" placeholder="50000" autocomplete="off">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_benefits.plans.duration')" for="membership-days" name="durationDays" required>
                        <input id="membership-days" type="number" min="1" max="3650" inputmode="numeric" wire:model="durationDays">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_benefits.common.sort_order')" for="membership-sort" name="sortOrder" :help="__('manager_benefits.common.sort_help')">
                        <input id="membership-sort" type="number" min="0" max="65535" inputmode="numeric" wire:model="sortOrder">
                    </x-ui.field>
                </div>
                <section class="drawer-section">
                    <h3>{{ __('manager_benefits.plans.benefits') }} <span class="muted">· {{ __('manager_benefits.plans.benefits_hint', ['max' => $maxBenefits]) }}</span></h3>
                    <div class="repeat-list">
                        @foreach($benefits as $index => $row)
                            <div class="repeat-row benefits-row" wire:key="benefit-row-{{ $index }}">
                                <div class="repeat-row__fields benefits-row__fields">
                                    <div class="field">
                                        <label for="benefit-service-{{ $index }}">{{ __('manager_benefits.common.service') }}</label>
                                        <select id="benefit-service-{{ $index }}" wire:model="benefits.{{ $index }}.service">
                                            <option value="">{{ __('manager_benefits.panel.every_service') }}</option>
                                            @foreach($services as $service)
                                                <option value="{{ $service['uuid'] }}">{{ $service['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="benefit-type-{{ $index }}">{{ __('manager_benefits.plans.discount') }}</label>
                                        <select id="benefit-type-{{ $index }}" wire:model="benefits.{{ $index }}.type">
                                            <option value="percent">{{ __('manager_benefits.plans.percent_off') }}</option>
                                            <option value="fixed">{{ __('manager_benefits.plans.amount_off', ['currency' => $currency]) }}</option>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="benefit-value-{{ $index }}">{{ __('manager_benefits.plans.value') }}</label>
                                        <input id="benefit-value-{{ $index }}" type="text" dir="ltr" inputmode="decimal" wire:model="benefits.{{ $index }}.value" placeholder="10">
                                    </div>
                                    <div class="field">
                                        <label for="benefit-uses-{{ $index }}">{{ __('manager_benefits.plans.uses') }}</label>
                                        <input id="benefit-uses-{{ $index }}" type="number" min="1" max="999" inputmode="numeric" wire:model="benefits.{{ $index }}.uses" placeholder="{{ __('manager_benefits.plans.unlimited') }}">
                                    </div>
                                </div>
                                @if(count($benefits) > 1)
                                    <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeBenefitRow({{ $index }})" aria-label="{{ __('manager_benefits.common.remove_row') }}" title="{{ __('manager_benefits.common.remove_row') }}"><x-ui.icon name="trash" /></button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if(count($benefits) < $maxBenefits)
                        <button class="button button--ghost button--sm" type="button" wire:click="addBenefitRow"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.plans.add_benefit') }}</button>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ $editing ? __('ui.actions.save_changes') : __('manager_benefits.plans.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
