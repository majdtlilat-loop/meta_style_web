{{-- One service in the library. $row comes from LibraryPresenter::service(). --}}
<li @class(['catalog-row', 'is-inactive' => ! $row['active'], 'is-archived' => $row['archived']]) data-sortable-item="{{ $row['uuid'] }}" wire:key="svc-{{ $row['uuid'] }}">
    @if($sortable)
        <button type="button" class="catalog-row__handle" data-sortable-handle aria-label="{{ __('manager_catalog.services.drag', ['name' => $row['name']]) }}" title="{{ __('manager_catalog.ordering.drag_hint') }}"><x-ui.icon name="grip" size="16" /></button>
    @endif

    <span class="catalog-row__thumb" aria-hidden="true">
        @if($row['image'])
            <img src="{{ $row['image'] }}" alt="" loading="lazy">
        @else
            {{ $row['initial'] }}
        @endif
    </span>

    <div class="catalog-row__main">
        @if($can['update'] && ! $row['archived'])
            <button type="button" class="catalog-row__name" wire:click="$dispatchTo('center.catalog.service-editor', 'catalog-edit-service', { uuid: '{{ $row['uuid'] }}' })">{{ $row['name'] }}</button>
        @else
            <span class="catalog-row__name">{{ $row['name'] }}</span>
        @endif
        @if($row['meta'] !== '')
            <span class="catalog-row__meta">{{ $row['meta'] }}</span>
        @endif
    </div>

    <div class="catalog-row__facts">
        <span class="catalog-row__duration"><x-ui.icon name="clock" size="14" />{{ $row['duration'] }}</span>
        <span class="catalog-row__price">
            @if($row['price_from'])<span class="catalog-row__from">{{ __('manager_catalog.services.from') }}</span>@endif
            <x-ui.money :minor="$row['price_minor']" :currency="$currency" />
        </span>
    </div>

    <div class="catalog-row__badges">
        @if($row['archived'])
            <x-ui.status value="archived" :label="__('ui.states.archived')" />
        @else
            <x-ui.status :value="$row['active'] ? 'active' : 'inactive'" :label="$row['active'] ? __('ui.states.active') : __('ui.states.inactive')" />
            @unless($row['public'])
                <span class="badge" data-tone="neutral" title="{{ __('manager_catalog.states.hidden_hint') }}"><x-ui.icon name="eye-off" size="12" />{{ __('manager_catalog.states.hidden') }}</span>
            @endunless
            @unless($row['online'])
                <span class="badge" data-tone="neutral" title="{{ __('manager_catalog.states.offline_hint') }}"><x-ui.icon name="phone" size="12" />{{ __('manager_catalog.states.offline') }}</span>
            @endunless
        @endif
    </div>

    <div class="catalog-row__actions">
        @if($sortable)
            <button type="button" class="icon-button icon-button--sm" wire:click="moveServiceBy('{{ $row['uuid'] }}', -1)" @disabled($first) aria-label="{{ __('manager_catalog.ordering.move_up', ['name' => $row['name']]) }}" title="{{ __('ui.actions.move_up') }}"><x-ui.icon name="arrow-up" size="16" /></button>
            <button type="button" class="icon-button icon-button--sm" wire:click="moveServiceBy('{{ $row['uuid'] }}', 1)" @disabled($last) aria-label="{{ __('manager_catalog.ordering.move_down', ['name' => $row['name']]) }}" title="{{ __('ui.actions.move_down') }}"><x-ui.icon name="arrow-down" size="16" /></button>
        @endif
        @if($can['update'] && ! $row['archived'])
            <button type="button" class="icon-button icon-button--sm" wire:click="$dispatchTo('center.catalog.service-editor', 'catalog-edit-service', { uuid: '{{ $row['uuid'] }}' })" aria-label="{{ __('manager_catalog.services.edit', ['name' => $row['name']]) }}" title="{{ __('ui.actions.edit') }}"><x-ui.icon name="edit" size="16" /></button>
        @endif
        @if($row['archived'] ? $can['archive'] : ($can['create'] || $can['update'] || $can['archive']))
            <details class="dropdown" data-popover>
                <summary class="icon-button icon-button--sm" aria-label="{{ __('manager_catalog.services.actions', ['name' => $row['name']]) }}" title="{{ __('ui.actions.more') }}"><x-ui.icon name="more" size="16" /></summary>
                <div class="dropdown__panel" role="menu">
                    @if($row['archived'])
                        @if($can['archive'])
                            <button class="menu-item" role="menuitem" type="button" wire:click="restoreService('{{ $row['uuid'] }}')"><x-ui.icon name="undo" />{{ __('ui.actions.restore') }}</button>
                            <p class="menu-note">{{ __('manager_catalog.services.restore_hint') }}</p>
                        @endif
                    @else
                        @if($can['create'])
                            <button class="menu-item" role="menuitem" type="button" wire:click="duplicateService('{{ $row['uuid'] }}')"><x-ui.icon name="copy" />{{ __('manager_catalog.actions.duplicate') }}</button>
                        @endif
                        @if($can['update'])
                            <button class="menu-item" role="menuitem" type="button" wire:click="startMove('{{ $row['uuid'] }}')"><x-ui.icon name="move" />{{ __('manager_catalog.actions.move_to_category') }}</button>
                            <div class="menu-separator" role="separator"></div>
                            <button class="menu-item" role="menuitem" type="button" wire:click="setServiceActive('{{ $row['uuid'] }}', {{ $row['active'] ? 'false' : 'true' }})"><x-ui.icon :name="$row['active'] ? 'pause' : 'play'" />{{ $row['active'] ? __('ui.actions.deactivate') : __('ui.actions.activate') }}</button>
                            <button class="menu-item" role="menuitem" type="button" wire:click="setServicePublic('{{ $row['uuid'] }}', {{ $row['public'] ? 'false' : 'true' }})"><x-ui.icon :name="$row['public'] ? 'eye-off' : 'eye'" />{{ $row['public'] ? __('manager_catalog.actions.hide') : __('manager_catalog.actions.show') }}</button>
                            <button class="menu-item" role="menuitem" type="button" wire:click="setServiceOnline('{{ $row['uuid'] }}', {{ $row['online'] ? 'false' : 'true' }})"><x-ui.icon name="calendar" />{{ $row['online'] ? __('manager_catalog.actions.online_off') : __('manager_catalog.actions.online_on') }}</button>
                        @endif
                        @if($can['archive'])
                            <div class="menu-separator" role="separator"></div>
                            <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="archiveService('{{ $row['uuid'] }}')"
                                wire:confirm="{{ __('manager_catalog.services.archive_confirm', ['name' => $row['name']]) }}"
                                data-confirm-title="{{ __('manager_catalog.services.archive_title') }}" data-confirm-tone="danger"><x-ui.icon name="archive" />{{ __('ui.actions.archive') }}</button>
                        @endif
                    @endif
                </div>
            </details>
        @endif
    </div>
</li>
