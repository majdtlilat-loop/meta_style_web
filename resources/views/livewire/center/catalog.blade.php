{{--
    Manager › Services — the service library.

    Categories down the side (drag, or Move up / Move down), their services as
    ordered lists. Every change is one Catalog Action; the drawers, the photo
    gallery and the quick add are child components that announce
    `catalog-changed`. No logic here: the component and LibraryPresenter hand
    over plain arrays.
--}}
<div class="catalog">
    <x-ui.page-header :title="__('manager_catalog.title')">
        <x-slot:meta>
            <span class="result-count">
                {{ trans_choice('manager_catalog.summary.services', $library['live'], ['count' => number_format($library['live'])]) }}
                · {{ trans_choice('manager_catalog.summary.categories', count($categories), ['count' => number_format(count($categories))]) }}
            </span>
        </x-slot:meta>
        @if($can['categories'] || $can['create'])
            <x-slot:actions>
                @if($can['categories'])
                    <x-ui.button variant="secondary" icon="plus" wire:click="$dispatchTo('center.catalog.category-editor', 'catalog-create-category')">{{ __('manager_catalog.actions.add_category') }}</x-ui.button>
                @endif
                @if($can['create'])
                    <x-ui.button icon="plus" wire:click="$dispatchTo('center.catalog.service-editor', 'catalog-create-service', { category: '{{ $category }}' })">{{ __('manager_catalog.actions.add_service') }}</x-ui.button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    @if($emptyLibrary)
        <x-ui.card>
            <x-ui.empty-state icon="catalog" :title="__('manager_catalog.empty.library_title')">
                <div class="cluster cluster--tight catalog-empty__actions">
                    @if($can['create'])
                        <x-ui.button icon="plus" wire:click="$dispatchTo('center.catalog.service-editor', 'catalog-create-service')">{{ __('manager_catalog.actions.add_service') }}</x-ui.button>
                    @endif
                    @if($can['categories'])
                        <x-ui.button variant="secondary" icon="plus" wire:click="$dispatchTo('center.catalog.category-editor', 'catalog-create-category')">{{ __('manager_catalog.actions.add_category') }}</x-ui.button>
                    @endif
                </div>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="catalog-layout">
            <aside class="catalog-nav" x-data="{ open: false }" aria-labelledby="catalog-nav-title" data-float-popovers>
                <div class="catalog-nav__head">
                    <div class="cluster cluster--tight">
                        <h2 id="catalog-nav-title">{{ __('manager_catalog.categories.title') }}</h2>
                        @if($can['categories'] && $categories !== [])
                            <span class="info-tip" title="{{ __('manager_catalog.ordering.categories_hint') }}" aria-label="{{ __('manager_catalog.ordering.categories_hint') }}" role="img"><x-ui.icon name="info" size="16" /></span>
                        @endif
                    </div>
                    @if($can['categories'])
                        <button type="button" class="icon-button icon-button--sm" wire:click="$dispatchTo('center.catalog.category-editor', 'catalog-create-category')" aria-label="{{ __('manager_catalog.actions.add_category') }}" title="{{ __('manager_catalog.actions.add_category') }}"><x-ui.icon name="plus" size="16" /></button>
                    @endif
                </div>

                <button type="button" class="catalog-nav__toggle" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="catalog-nav-body">
                    <x-ui.icon name="layers" size="16" />
                    <span>{{ $currentLabel }}</span>
                    <x-ui.icon name="chevron-down" size="16" />
                </button>

                <div id="catalog-nav-body" class="catalog-nav__body" :class="{ 'is-open': open }">
                    <button type="button" class="catalog-nav__entry" wire:click="selectCategory('')" x-on:click="open = false" aria-pressed="{{ $category === '' ? 'true' : 'false' }}">
                        <span class="catalog-nav__icon" aria-hidden="true"><x-ui.icon name="grid" size="16" /></span>
                        <span class="catalog-nav__label">{{ __('manager_catalog.categories.all') }}</span>
                        <span class="catalog-nav__count">{{ number_format($library['live']) }}</span>
                    </button>

                    @if($categories !== [])
                        <ul class="catalog-cats" role="list" @if($can['categories']) x-data x-sortable="moveCategory" data-sortable-group="categories" @endif>
                            @foreach($categories as $row)
                                <li @class(['catalog-cat', 'is-selected' => $category === $row['uuid'], 'is-inactive' => ! $row['active']]) data-sortable-item="{{ $row['uuid'] }}" wire:key="cat-{{ $row['uuid'] }}">
                                    @if($can['categories'])
                                        <button type="button" class="catalog-cat__handle" data-sortable-handle aria-label="{{ __('manager_catalog.categories.drag', ['name' => $row['name']]) }}" title="{{ __('manager_catalog.ordering.drag_hint') }}"><x-ui.icon name="grip" size="14" /></button>
                                    @endif
                                    <button type="button" class="catalog-cat__select" wire:click="selectCategory('{{ $row['uuid'] }}')" x-on:click="open = false" aria-pressed="{{ $category === $row['uuid'] ? 'true' : 'false' }}">
                                        @if($row['image'])
                                            <img class="catalog-cat__thumb" src="{{ $row['image'] }}" alt="" loading="lazy">
                                        @else
                                            <span class="catalog-cat__thumb" aria-hidden="true">{{ $row['initial'] }}</span>
                                        @endif
                                        <span class="catalog-cat__name">{{ $row['name'] }}</span>
                                        @unless($row['public'])
                                            <span class="catalog-cat__flag" title="{{ __('manager_catalog.states.hidden') }}"><x-ui.icon name="eye-off" size="14" /><span class="sr-only">{{ __('manager_catalog.states.hidden') }}</span></span>
                                        @endunless
                                        @unless($row['active'])
                                            <span class="sr-only">{{ __('ui.states.inactive') }}</span>
                                        @endunless
                                        <span class="catalog-nav__count">{{ number_format($row['count']) }}</span>
                                    </button>
                                    @if($can['categories'])
                                        <div class="catalog-cat__tools">
                                            <button type="button" class="icon-button icon-button--xs" wire:click="moveCategoryBy('{{ $row['uuid'] }}', -1)" @disabled($loop->first) aria-label="{{ __('manager_catalog.ordering.move_up', ['name' => $row['name']]) }}" title="{{ __('ui.actions.move_up') }}"><x-ui.icon name="arrow-up" size="14" /></button>
                                            <button type="button" class="icon-button icon-button--xs" wire:click="moveCategoryBy('{{ $row['uuid'] }}', 1)" @disabled($loop->last) aria-label="{{ __('manager_catalog.ordering.move_down', ['name' => $row['name']]) }}" title="{{ __('ui.actions.move_down') }}"><x-ui.icon name="arrow-down" size="14" /></button>
                                            <details class="dropdown" data-popover>
                                                <summary class="icon-button icon-button--xs" aria-label="{{ __('manager_catalog.categories.actions', ['name' => $row['name']]) }}" title="{{ __('ui.actions.more') }}"><x-ui.icon name="more" size="14" /></summary>
                                                <div class="dropdown__panel" role="menu">
                                                    <button class="menu-item" role="menuitem" type="button" wire:click="$dispatchTo('center.catalog.category-editor', 'catalog-edit-category', { uuid: '{{ $row['uuid'] }}' })"><x-ui.icon name="edit" />{{ __('manager_catalog.categories.edit') }}</button>
                                                    <button class="menu-item" role="menuitem" type="button" wire:click="setCategoryPublic('{{ $row['uuid'] }}', {{ $row['public'] ? 'false' : 'true' }})"><x-ui.icon :name="$row['public'] ? 'eye-off' : 'eye'" />{{ $row['public'] ? __('manager_catalog.actions.hide') : __('manager_catalog.actions.show') }}</button>
                                                    <button class="menu-item" role="menuitem" type="button" wire:click="setCategoryActive('{{ $row['uuid'] }}', {{ $row['active'] ? 'false' : 'true' }})"><x-ui.icon :name="$row['active'] ? 'pause' : 'play'" />{{ $row['active'] ? __('ui.actions.deactivate') : __('ui.actions.activate') }}</button>
                                                    <div class="menu-separator" role="separator"></div>
                                                    <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="archiveCategory('{{ $row['uuid'] }}')"
                                                        wire:confirm="{{ trans_choice('manager_catalog.categories.archive_confirm', $row['count'], ['count' => $row['count'], 'name' => $row['name']]) }}"
                                                        data-confirm-title="{{ __('manager_catalog.categories.archive_title') }}" data-confirm-tone="danger"><x-ui.icon name="archive" />{{ __('ui.actions.archive') }}</button>
                                                </div>
                                            </details>
                                        </div>
                                    @endif
                                    @if($can['update'])
                                        <ul class="catalog-drop" data-sortable-group="services" data-sortable-container="end:{{ $row['uuid'] }}" aria-hidden="true"></ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div @class(['catalog-cat', 'catalog-cat--fixed', 'is-selected' => $category === 'none'])>
                        <button type="button" class="catalog-cat__select" wire:click="selectCategory('none')" x-on:click="open = false" aria-pressed="{{ $category === 'none' ? 'true' : 'false' }}">
                            <span class="catalog-cat__thumb catalog-cat__thumb--muted" aria-hidden="true"><x-ui.icon name="tag" size="14" /></span>
                            <span class="catalog-cat__name">{{ __('manager_catalog.categories.uncategorised') }}</span>
                            <span class="catalog-nav__count">{{ number_format($library['uncategorised']) }}</span>
                        </button>
                        @if($can['update'])
                            <ul class="catalog-drop" data-sortable-group="services" data-sortable-container="end:none" aria-hidden="true"></ul>
                        @endif
                    </div>

                    @if($archivedCategories !== [])
                        <details class="catalog-nav__archived">
                            <summary>{{ __('manager_catalog.categories.archived', ['count' => count($archivedCategories)]) }}</summary>
                            <ul role="list">
                                @foreach($archivedCategories as $row)
                                    <li wire:key="archived-cat-{{ $row['uuid'] }}">
                                        <span>{{ $row['name'] }}</span>
                                        <button type="button" class="button button--ghost button--sm" wire:click="restoreCategory('{{ $row['uuid'] }}')"><x-ui.icon name="undo" size="14" />{{ __('ui.actions.restore') }}</button>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            </aside>

            <section class="catalog-main" aria-labelledby="catalog-main-title">
                @if($selected)
                    <header class="catalog-heading">
                        @if($selected['image'])
                            <img class="catalog-heading__image" src="{{ $selected['image'] }}" alt="">
                        @endif
                        <div class="catalog-heading__text">
                            <h2 id="catalog-main-title">{{ $selected['name'] }}</h2>
                            @if($selected['description'] !== '')
                                <p>{{ $selected['description'] }}</p>
                            @endif
                            <div class="cluster cluster--tight">
                                <x-ui.status :value="$selected['active'] ? 'active' : 'inactive'" :label="$selected['active'] ? __('ui.states.active') : __('ui.states.inactive')" />
                                <span class="badge" data-tone="{{ $selected['public'] ? 'success' : 'neutral' }}"><x-ui.icon :name="$selected['public'] ? 'eye' : 'eye-off'" size="12" />{{ $selected['public'] ? __('manager_catalog.states.on_menu') : __('manager_catalog.states.hidden') }}</span>
                            </div>
                        </div>
                        @if($can['categories'])
                            <x-ui.button variant="secondary" size="sm" icon="edit" wire:click="$dispatchTo('center.catalog.category-editor', 'catalog-edit-category', { uuid: '{{ $selected['uuid'] }}' })">{{ __('manager_catalog.categories.edit') }}</x-ui.button>
                        @endif
                    </header>
                @else
                    <h2 id="catalog-main-title" class="sr-only">{{ $currentLabel }}</h2>
                @endif

                <div class="catalog-toolbar" role="search" aria-label="{{ __('manager_catalog.filters.label') }}">
                    <div class="search-input catalog-toolbar__search">
                        <x-ui.icon name="search" />
                        <input id="catalog-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('manager_catalog.filters.search') }}" aria-label="{{ __('manager_catalog.filters.search') }}" autocomplete="off">
                    </div>
                    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('manager_catalog.filters.status') }}">
                        @foreach($statusTabs as $tab)
                            <button type="button" wire:click="setStatus('{{ $tab['value'] }}')" aria-pressed="{{ $status === $tab['value'] ? 'true' : 'false' }}">{{ $tab['label'] }}<span class="segmented__count">{{ number_format($tab['count']) }}</span></button>
                        @endforeach
                    </div>
                    <label class="sr-only" for="catalog-visibility">{{ __('manager_catalog.filters.visibility') }}</label>
                    <select id="catalog-visibility" class="catalog-toolbar__select" wire:model.live="visibility">
                        @foreach($visibilityOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @if($filtered)
                        <button type="button" class="button button--ghost button--sm" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
                    @endif
                </div>

                @if($filtered && $can['update'] && ! $noMatches)
                    <p class="catalog-hint"><x-ui.icon name="info" size="14" />{{ __('manager_catalog.ordering.filtered') }}</p>
                @endif

                @if($can['create'] && $status !== 'archived')
                    <livewire:center.catalog.quick-add :category="$category" wire:key="catalog-quick-add" />
                @endif

                <div class="catalog-lists" data-float-popovers wire:loading.class="is-refreshing" wire:target="search,status,setStatus,visibility,selectCategory,clearFilters">
                    @if($noMatches)
                        <x-ui.card>
                            <x-ui.empty-state icon="filter" :title="__('manager_catalog.empty.no_matches_title')">
                                <button type="button" class="button button--secondary button--sm" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
                            </x-ui.empty-state>
                        </x-ui.card>
                    @else
                        @foreach($sections as $section)
                            <section class="catalog-section" wire:key="section-{{ $section['key'] ?? 'results' }}" @if($section['title'] !== null) aria-label="{{ $section['title'] }}" @endif>
                                @if($section['title'] !== null)
                                    <header class="catalog-section__head">
                                        @if($section['key'] !== 'none')
                                            <button type="button" class="catalog-section__title" wire:click="selectCategory('{{ $section['key'] }}')">{{ $section['title'] }}</button>
                                        @else
                                            <span class="catalog-section__title">{{ $section['title'] }}</span>
                                        @endif
                                        <span class="catalog-nav__count">{{ number_format(count($section['rows'])) }}</span>
                                        @if($section['category'] !== null && ! $section['category']['public'])
                                            <span class="badge" data-tone="neutral"><x-ui.icon name="eye-off" size="12" />{{ __('manager_catalog.states.hidden') }}</span>
                                        @endif
                                        @if($section['category'] !== null && ! $section['category']['active'])
                                            <x-ui.status value="inactive" :label="__('ui.states.inactive')" />
                                        @endif
                                    </header>
                                @endif
                                <ul class="catalog-list" role="list" @if($section['sortable']) x-data x-sortable="moveService" data-sortable-group="services" data-sortable-container="{{ $section['key'] }}" @endif>
                                    @forelse($section['rows'] as $row)
                                        @include('livewire.center.catalog.partials.service-row', ['row' => $row, 'sortable' => $section['sortable'], 'first' => $loop->first, 'last' => $loop->last])
                                    @empty
                                        <li class="catalog-list__empty">{{ $section['sortable'] ? __('manager_catalog.sections.empty_drop') : __('manager_catalog.sections.empty') }}</li>
                                    @endforelse
                                </ul>
                            </section>
                        @endforeach
                    @endif
                </div>
            </section>
        </div>
    @endif

    @if($moving)
        <x-ui.modal :title="__('manager_catalog.move.title', ['name' => $moving['name']])" icon="move" close="cancelMove" submit="confirmMove">
            <x-ui.field :label="__('manager_catalog.move.target')" for="catalog-move-target">
                <select id="catalog-move-target" wire:model="moveTarget">
                    @foreach($categories as $row)
                        <option value="{{ $row['uuid'] }}">{{ $row['name'] }}@if($row['uuid'] === $moving['category']) · {{ __('manager_catalog.move.current') }}@endif</option>
                    @endforeach
                    <option value="none">{{ __('manager_catalog.categories.uncategorised') }}@if($moving['category'] === 'none') · {{ __('manager_catalog.move.current') }}@endif</option>
                </select>
            </x-ui.field>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="cancelMove">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="disabled" wire:target="confirmMove"><x-ui.icon name="move" size="16" />{{ __('manager_catalog.move.submit') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <livewire:center.catalog.service-editor wire:key="catalog-service-editor" />
    <livewire:center.catalog.category-editor wire:key="catalog-category-editor" />
</div>
