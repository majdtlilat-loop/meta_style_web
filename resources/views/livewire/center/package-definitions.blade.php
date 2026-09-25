{{--
    Service packages: prepaid sessions of named services — and who holds one.

    docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§13, 15, 22, 27. Sold at the till;
    a package becomes the customer's once its invoice is settled, and a session
    is used only by a service actually performed at checkout.
--}}
<div class="stack benefits-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.packages')">
        @if($canManage)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openForm"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.packages.add') }}</button>
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
        @if($definitions === [])
            <x-ui.card>
                @if($view === 'archived')
                    <x-ui.empty-state compact icon="archive" :title="__('manager_benefits.packages.no_archived')" />
                @else
                    <x-ui.empty-state icon="packages" :title="__('manager_benefits.packages.empty_title')">
                        @if($canManage)<button class="button button--sm" type="button" wire:click="openForm"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.packages.add') }}</button>@endif
                    </x-ui.empty-state>
                @endif
            </x-ui.card>
        @else
            <div class="plan-grid">
                @foreach($definitions as $definition)
                    <article class="plan-card" wire:key="definition-{{ $definition['uuid'] }}" @if($definition['archived']) data-archived @endif>
                        <header class="plan-card__head">
                            <h2>{{ $definition['name'] }}</h2>
                            <x-ui.status tone="neutral" :label="trans_choice('manager_benefits.packages.valid_days', (int) $definition['validity_days'], ['n' => $definition['validity_days']])" :dot="false" />
                        </header>
                        <p class="plan-card__price"><span dir="ltr" class="tabular">{{ $definition['price']['formatted'] }}</span></p>
                        <ul class="plan-card__facts">
                            @foreach($definition['items'] as $item)
                                <li>
                                    <x-ui.icon name="scissors" size="16" />
                                    <span>{{ $item['service_name'] ?? '—' }}@if($item['variation_name'] !== null) · {{ $item['variation_name'] }}@endif <strong class="tabular">× {{ $item['quantity'] }}</strong></span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="benefits-holders"><x-ui.icon name="users" size="14" />{{ trans_choice('manager_benefits.packages.holders_count', (int) $definition['holders'], ['n' => number_format((int) $definition['holders'])]) }}</p>
                        @if($canManage)
                            <footer class="plan-card__foot cluster cluster--tight">
                                @if($definition['archived'])
                                    <button class="button button--secondary button--sm" type="button" wire:click="restore('{{ $definition['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('manager_benefits.plans.put_back') }}</button>
                                @else
                                    <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="archive('{{ $definition['uuid'] }}')" wire:confirm="{{ __('manager_benefits.packages.archive_body', ['name' => $definition['name']]) }}" data-confirm-title="{{ __('manager_benefits.packages.archive_title') }}" data-confirm-label="{{ __('ui.actions.archive') }}" data-confirm-tone="danger"><x-ui.icon name="archive" size="16" />{{ __('ui.actions.archive') }}</button>
                                    <button class="button button--secondary button--sm" type="button" wire:click="edit('{{ $definition['uuid'] }}')"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                                @endif
                            </footer>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    <livewire:center.benefits.package-holders />

    @if($canManage && $showForm)
        <x-ui.drawer :title="$editing ? __('manager_benefits.packages.edit_title') : __('manager_benefits.packages.add')" :description="$editing ? __('manager_benefits.packages.edit_hint') : null" close="closeForm" submit="save" size="lg">
            <div class="stack">
                @if($error !== '')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>@endif
                <x-ui.lang-tabs id="package-name" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                    ['name' => 'name', 'label' => __('manager_benefits.common.name'), 'max' => 120, 'required' => true],
                ]" />
                <div class="form-grid form-grid--3">
                    <x-ui.field :label="__('manager_benefits.common.price', ['currency' => $currency])" for="package-price" name="price" required>
                        <input id="package-price" type="text" dir="ltr" inputmode="decimal" wire:model="price" placeholder="80000" autocomplete="off">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_benefits.packages.validity')" for="package-days" name="validityDays" required>
                        <input id="package-days" type="number" min="1" max="3650" inputmode="numeric" wire:model="validityDays">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_benefits.common.sort_order')" for="package-sort" name="sortOrder" :help="__('manager_benefits.common.sort_help')">
                        <input id="package-sort" type="number" min="0" max="65535" inputmode="numeric" wire:model="sortOrder">
                    </x-ui.field>
                </div>
                <section class="drawer-section">
                    <h3>{{ __('manager_benefits.packages.sessions') }} <span class="muted">· {{ __('manager_benefits.packages.sessions_hint') }}</span></h3>
                    <div class="repeat-list">
                        @foreach($items as $index => $row)
                            <div class="repeat-row benefits-row" wire:key="item-row-{{ $index }}">
                                <div class="repeat-row__fields benefits-row__fields benefits-row__fields--3">
                                    <div class="field">
                                        <label for="item-service-{{ $index }}">{{ __('manager_benefits.common.service') }}<span class="required" aria-hidden="true">*</span></label>
                                        <select id="item-service-{{ $index }}" wire:model.live="items.{{ $index }}.service">
                                            <option value="">{{ __('manager_benefits.packages.choose_service') }}</option>
                                            @foreach($services as $service)
                                                <option value="{{ $service['uuid'] }}">{{ $service['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="item-variation-{{ $index }}">{{ __('manager_benefits.packages.variation') }}</label>
                                        <select id="item-variation-{{ $index }}" wire:model="items.{{ $index }}.variation" @disabled(($variations[$row['service']] ?? []) === [])>
                                            <option value="">{{ __('manager_benefits.packages.any_variation') }}</option>
                                            @foreach($variations[$row['service']] ?? [] as $variation)
                                                <option value="{{ $variation['uuid'] }}">{{ $variation['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label for="item-quantity-{{ $index }}">{{ __('manager_benefits.packages.quantity') }}</label>
                                        <input id="item-quantity-{{ $index }}" type="number" min="1" max="999" inputmode="numeric" wire:model="items.{{ $index }}.quantity">
                                    </div>
                                </div>
                                @if(count($items) > 1)
                                    <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeItemRow({{ $index }})" aria-label="{{ __('manager_benefits.common.remove_row') }}" title="{{ __('manager_benefits.common.remove_row') }}"><x-ui.icon name="trash" /></button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if(count($items) < $maxItems)
                        <button class="button button--ghost button--sm" type="button" wire:click="addItemRow"><x-ui.icon name="plus" size="16" />{{ __('manager_benefits.packages.add_item') }}</button>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ $editing ? __('ui.actions.save_changes') : __('manager_benefits.packages.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
