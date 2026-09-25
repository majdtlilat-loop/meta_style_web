{{--
    Staff CRM — the customer list.

    Every customer here arrived already masked or unmasked by CustomerPresenter,
    the same one the API uses. There is no masking logic in this template, on
    purpose: hiding a value in Blade still sends it to the browser
    (docs/06-AUTH-ROLES-PERMISSIONS.md §6). The search box is never bound to
    the URL: it can hold a phone number or an email.
--}}
<div class="stack crm">
    <x-ui.page-header :title="__('ui.manager_nav.items.customers')">
        <x-slot:meta>
            <span class="result-count">{{ trans_choice('ui.table.results', $page->total(), ['count' => number_format($page->total())]) }}</span>
        </x-slot:meta>
        @if($canCreate || $canManageTags)
            <x-slot:actions>
                @if($canManageTags)
                    <button class="button button--secondary" type="button" x-on:click="$dispatch('open-customer-tags')"><x-ui.icon name="tag" size="16" />{{ __('manager_customers.tags.title') }}</button>
                @endif
                @if($canCreate)
                    <button class="button" type="button" wire:click="create" wire:loading.attr="data-loading" wire:target="create"><x-ui.icon name="user-plus" size="16" />{{ __('manager_customers.actions.add') }}</button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    <div class="crm-toolbar">
        <div class="segmented" role="group" aria-label="{{ __('manager_customers.filters.status') }}">
            <button type="button" wire:click="$set('archived', false)" aria-pressed="{{ $archived ? 'false' : 'true' }}">{{ __('manager_customers.filters.active') }}</button>
            <button type="button" wire:click="$set('archived', true)" aria-pressed="{{ $archived ? 'true' : 'false' }}"><x-ui.icon name="archive" size="14" />{{ __('manager_customers.filters.archived') }}</button>
        </div>
    </div>

    <form class="filter-bar" role="search" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}" data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="customer-search">{{ __('ui.fields.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="customer-search" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $canSeeContact ? __('manager_customers.list.search_full') : __('manager_customers.list.search_name') }}" autocomplete="off" maxlength="120">
            </div>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="customer-account">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
        </button>
        <div class="field">
            <label for="customer-account">{{ __('manager_customers.filters.account') }}</label>
            <select id="customer-account" wire:model.live="registered">
                <option value="">{{ __('manager_customers.filters.everyone') }}</option>
                <option value="yes">{{ __('manager_customers.filters.has_account') }}</option>
                <option value="no">{{ __('manager_customers.filters.guest') }}</option>
            </select>
        </div>
        <div class="field">
            <label for="customer-visited">{{ __('manager_customers.filters.visits') }}</label>
            <select id="customer-visited" wire:model.live="visited">
                <option value="">{{ __('manager_customers.filters.everyone') }}</option>
                <option value="yes">{{ __('manager_customers.filters.visited') }}</option>
                <option value="no">{{ __('manager_customers.filters.never_visited') }}</option>
            </select>
        </div>
        @if($tagOptions !== [])
            <div class="field">
                <label for="customer-tag">{{ __('manager_customers.filters.tag') }}</label>
                <select id="customer-tag" wire:model.live="tag">
                    <option value="">{{ __('manager_customers.filters.any_tag') }}</option>
                    @foreach($tagOptions as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
            </div>
        @endif
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,registered,tag,visited,archived,clearFilters,gotoPage,nextPage,previousPage">
        @if($customers === [])
            @if($hasFilters)
                <x-ui.empty-state icon="filter" :title="__('manager_customers.list.no_match')">
                    <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="customers" :title="__('manager_customers.list.empty_title')">
                    @if($canCreate)
                        <button class="button button--sm" type="button" wire:click="create"><x-ui.icon name="user-plus" size="16" />{{ __('manager_customers.actions.add') }}</button>
                    @endif
                </x-ui.empty-state>
            @endif
        @else
            <table>
                <caption class="sr-only">{{ __('ui.manager_nav.items.customers') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('manager_customers.list.customer') }}</th>
                        <th scope="col">{{ __('manager_customers.list.contact') }}</th>
                        <th scope="col">{{ __('manager_customers.list.account') }}</th>
                        <th scope="col">{{ __('manager_customers.list.last_visit') }}</th>
                        <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($customers as $customer)
                        <tr wire:key="customer-{{ $customer['uuid'] }}" @if($customer['is_archived']) data-muted="true" @endif>
                            <td data-label="{{ __('manager_customers.list.customer') }}" data-primary>
                                <span class="cell-person">
                                    <span class="avatar" aria-hidden="true">{{ $customer['initials'] }}</span>
                                    <span>
                                        <a class="cell-title" href="{{ $customer['profile_url'] }}" wire:navigate>{{ $customer['name'] }}</a>
                                        @if($customer['tags'] !== [] || $customer['is_archived'])
                                            <span class="chip-list">
                                                @foreach($customer['tags'] as $t)<span class="chip">{{ $t['name'] }}</span>@endforeach
                                                @if($customer['is_archived'])<span class="chip chip--muted">{{ __('manager_customers.status.archived') }}</span>@endif
                                            </span>
                                        @endif
                                    </span>
                                </span>
                            </td>
                            <td data-label="{{ __('manager_customers.list.contact') }}">
                                @if($customer['phone'] === null && $customer['email'] === null)
                                    <span class="muted">—</span>
                                @else
                                    @if($customer['phone'] !== null)<span class="cell-title tabular" dir="ltr">{{ $customer['phone'] }}</span>@endif
                                    @if($customer['email'] !== null)<span class="cell-sub" dir="ltr">{{ $customer['email'] }}</span>@endif
                                    @if($customer['contact_masked'])<span class="cell-sub crm-masked"><x-ui.icon name="lock" size="12" />{{ __('manager_customers.list.masked') }}</span>@endif
                                @endif
                            </td>
                            <td data-label="{{ __('manager_customers.list.account') }}">
                                <x-ui.status :value="$customer['is_registered'] ? 'active' : 'inactive'" :label="$customer['is_registered'] ? __('manager_customers.status.registered') : __('manager_customers.status.guest')" />
                            </td>
                            <td data-label="{{ __('manager_customers.list.last_visit') }}">
                                @if($customer['last_visit'] !== null)
                                    <span title="{{ $customer['last_visit_title'] }}">{{ $customer['last_visit'] }}</span>
                                @else
                                    <span class="muted">{{ __('manager_customers.list.no_visit') }}</span>
                                @endif
                            </td>
                            <td class="actions">
                                <a class="button button--secondary button--sm" href="{{ $customer['profile_url'] }}" wire:navigate>{{ __('manager_customers.actions.open') }}</a>
                                @if($canUpdate || $canArchive)
                                    <details class="dropdown" data-popover>
                                        <summary class="icon-button icon-button--sm" aria-label="{{ __('manager_customers.actions.more', ['name' => $customer['name']]) }}"><x-ui.icon name="more" /></summary>
                                        <div class="dropdown__panel" role="menu">
                                            @if($canUpdate)
                                                <button class="menu-item" role="menuitem" type="button" wire:click="edit('{{ $customer['uuid'] }}')"><x-ui.icon name="edit" />{{ __('ui.actions.edit') }}</button>
                                            @endif
                                            @if($canArchive)
                                                @if($customer['is_archived'])
                                                    <button class="menu-item" role="menuitem" type="button" wire:click="restore('{{ $customer['uuid'] }}')"><x-ui.icon name="undo" />{{ __('ui.actions.restore') }}</button>
                                                @else
                                                    <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="archive('{{ $customer['uuid'] }}')" wire:confirm="{{ __('manager_customers.confirm.archive_body', ['name' => $customer['name']]) }}" data-confirm-title="{{ __('manager_customers.confirm.archive_title') }}" data-confirm-label="{{ __('ui.actions.archive') }}" data-confirm-tone="danger"><x-ui.icon name="archive" />{{ __('ui.actions.archive') }}</button>
                                                @endif
                                            @endif
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{ $page->links() }}

    @include('livewire.center.customers.form')

    @if($canManageTags)
        <livewire:center.customers.tags-drawer />
    @endif
</div>
