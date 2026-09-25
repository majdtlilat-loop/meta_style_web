{{--
    One customer's page.

    The profile shape comes from CustomerPresenter (contact already masked or
    not); every other tab is its own module's panel, authorised on the server
    by that panel. Archiving keeps all history; the login is a separate switch.
--}}
<div class="stack crm-profile">
    <nav aria-label="{{ __('manager_customers.profile.breadcrumb') }}">
        <ol class="breadcrumbs">
            <li><a href="{{ $listUrl }}" wire:navigate>{{ __('ui.manager_nav.items.customers') }}</a></li>
            <li><span aria-current="page">{{ $profile['name'] }}</span></li>
        </ol>
    </nav>

    <header class="record-header crm-profile__header">
        <div class="record-header__identity">
            <span class="record-header__mark" aria-hidden="true">{{ $initials }}</span>
            <div>
                <h1>{{ $profile['name'] }}</h1>
                <div class="record-header__meta">
                    @if($profile['is_archived'])
                        <x-ui.status value="archived" :label="__('manager_customers.status.archived')" />
                    @endif
                    @if($profile['account'])
                        <x-ui.status :value="$profile['account']['is_active'] ? 'active' : 'inactive'" :label="$profile['account']['is_active'] ? __('manager_customers.status.login_active') : __('manager_customers.status.login_off')" />
                    @else
                        <x-ui.status value="inactive" :label="__('manager_customers.status.guest')" />
                    @endif
                    @foreach($profile['tags'] as $t)<span class="chip">{{ $t['name'] }}</span>@endforeach
                    <span class="muted">{{ __('manager_customers.profile.since', ['date' => $facts['since']]) }}</span>
                </div>
            </div>
        </div>
        @if($canUpdate || $canArchive || ($canManageLogin && $profile['account']))
            <div class="cluster">
                @if($canUpdate)
                    <button class="button button--secondary" type="button" wire:click="edit('{{ $profile['uuid'] }}')" wire:loading.attr="data-loading" wire:target="edit"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                @endif
                @if($canArchive || ($canManageLogin && $profile['account']))
                    <details class="dropdown" data-popover>
                        <summary class="button button--secondary" aria-label="{{ __('manager_customers.actions.more', ['name' => $profile['name']]) }}">{{ __('ui.actions.manage') }}<x-ui.icon name="chevron-down" size="16" /></summary>
                        <div class="dropdown__panel" role="menu">
                            @if($canManageLogin && $profile['account'])
                                @if($profile['account']['is_active'])
                                    <button class="menu-item" role="menuitem" type="button" wire:click="setLogin(false)" wire:confirm="{{ __('manager_customers.confirm.login_off_body', ['name' => $profile['name']]) }}" data-confirm-title="{{ __('manager_customers.confirm.login_off_title') }}" data-confirm-label="{{ __('manager_customers.actions.login_off') }}" data-confirm-tone="danger"><x-ui.icon name="user-x" />{{ __('manager_customers.actions.login_off') }}</button>
                                @elseif(! $profile['is_archived'])
                                    <button class="menu-item" role="menuitem" type="button" wire:click="setLogin(true)"><x-ui.icon name="user-check" />{{ __('manager_customers.actions.login_on') }}</button>
                                @endif
                            @endif
                            @if($canArchive)
                                @if($profile['is_archived'])
                                    <button class="menu-item" role="menuitem" type="button" wire:click="restore"><x-ui.icon name="undo" />{{ __('ui.actions.restore') }}</button>
                                @else
                                    <div class="menu-separator" role="separator"></div>
                                    <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="archive" wire:confirm="{{ __('manager_customers.confirm.archive_body', ['name' => $profile['name']]) }}" data-confirm-title="{{ __('manager_customers.confirm.archive_title') }}" data-confirm-label="{{ __('ui.actions.archive') }}" data-confirm-tone="danger"><x-ui.icon name="archive" />{{ __('ui.actions.archive') }}</button>
                                @endif
                            @endif
                        </div>
                    </details>
                @endif
            </div>
        @endif
    </header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    @if(count($tabs) > 1)
        <nav class="tabs tabs--scroll" aria-label="{{ __('manager_customers.profile.sections') }}">
            @foreach($tabs as $key)
                <button type="button" wire:click="showTab('{{ $key }}')" @if($tab === $key) aria-current="page" @endif>{{ __('manager_customers.tabs.'.$key) }}</button>
            @endforeach
        </nav>
    @endif

    <div class="stack" wire:loading.class="is-refreshing" wire:target="showTab">
        @switch($tab)
            @case('bookings')
                <livewire:center.customers.bookings-panel :customer="$profile['uuid']" :key="'bookings-'.$profile['uuid']" />
                @break
            @case('visits')
                <livewire:center.customers.visits-panel :customer="$profile['uuid']" :key="'visits-'.$profile['uuid']" />
                @break
            @case('purchases')
                <livewire:center.customers.purchases-panel :customer="$profile['uuid']" :key="'purchases-'.$profile['uuid']" />
                @break
            @case('loyalty')
                <livewire:center.customer-benefits-panel :customer="$profile['uuid']" section="loyalty" :key="'loyalty-'.$profile['uuid']" />
                @break
            @case('plans')
                <livewire:center.customer-benefits-panel :customer="$profile['uuid']" section="plans" :key="'plans-'.$profile['uuid']" />
                @break
            @case('reviews')
                <livewire:center.customers.reviews-panel :customer="$profile['uuid']" :key="'reviews-'.$profile['uuid']" />
                @break
            @case('notes')
                <livewire:center.customers.notes-panel :customer="$profile['uuid']" :key="'notes-'.$profile['uuid']" />
                @break
            @default
                <div class="record-grid">
                    <div class="stack">
                        <x-ui.card :title="__('manager_customers.profile.details')">
                            <dl class="kv-grid">
                                <div>
                                    <dt>{{ __('manager_customers.fields.phone') }}</dt>
                                    <dd dir="ltr" class="tabular">{{ $profile['phone'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('manager_customers.fields.email') }}</dt>
                                    <dd dir="ltr">{{ $profile['email'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('manager_customers.fields.language') }}</dt>
                                    <dd>{{ $facts['language'] ?? __('manager_customers.profile.not_set') }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('manager_customers.fields.date_of_birth') }}</dt>
                                    <dd>{{ $facts['date_of_birth'] ?? __('manager_customers.profile.not_set') }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('manager_customers.profile.source') }}</dt>
                                    <dd>{{ $facts['source'] }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('manager_customers.list.last_visit') }}</dt>
                                    <dd>@if($facts['last_visit'])<span title="{{ $facts['last_visit_date'] }}">{{ $facts['last_visit'] }}</span>@else<span class="muted">{{ __('manager_customers.list.no_visit') }}</span>@endif</dd>
                                </div>
                            </dl>
                            @if($profile['contact_masked'])
                                <p class="field-help crm-masked"><x-ui.icon name="lock" size="12" />{{ __('manager_customers.profile.masked_note') }}</p>
                            @endif
                        </x-ui.card>

                        <x-ui.card :title="__('manager_customers.fields.messages')">
                            <ul class="crm-prefs">
                                <li>
                                    <x-ui.status :value="$profile['preferences']['allow_operational_messages'] ? 'active' : 'inactive'" :label="$profile['preferences']['allow_operational_messages'] ? __('manager_customers.profile.operational_on') : __('manager_customers.profile.operational_off')" />
                                </li>
                                <li>
                                    <x-ui.status :value="$profile['preferences']['marketing_opt_in'] ? 'active' : 'inactive'" :label="$profile['preferences']['marketing_opt_in'] ? __('manager_customers.profile.marketing_on') : __('manager_customers.profile.marketing_off')" />
                                    @if($facts['marketing_since'])<span class="muted">{{ __('manager_customers.profile.marketing_since', ['date' => $facts['marketing_since']]) }}</span>@endif
                                </li>
                            </ul>
                        </x-ui.card>
                    </div>

                    <aside class="record-aside">
                        <x-ui.card :title="__('manager_customers.profile.account')">
                            @if($profile['account'])
                                <dl class="kv-grid crm-kv-tight">
                                    <div>
                                        <dt>{{ __('manager_customers.profile.login') }}</dt>
                                        <dd><x-ui.status :value="$profile['account']['is_active'] ? 'active' : 'inactive'" :label="$profile['account']['is_active'] ? __('manager_customers.status.login_active') : __('manager_customers.status.login_off')" /></dd>
                                    </div>
                                    <div>
                                        <dt>{{ __('manager_customers.profile.last_login') }}</dt>
                                        <dd>{{ $facts['last_login'] ?? __('manager_customers.profile.never') }}</dd>
                                    </div>
                                    <div>
                                        <dt>{{ __('manager_customers.profile.account_since') }}</dt>
                                        <dd>{{ $facts['account_since'] ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt>{{ __('manager_customers.profile.phone_check') }}</dt>
                                        <dd><x-ui.status :tone="$profile['account']['phone_verified'] ? 'success' : 'neutral'" :label="$profile['account']['phone_verified'] ? __('manager_customers.profile.verified') : __('manager_customers.profile.not_verified')" :dot="false" /></dd>
                                    </div>
                                </dl>
                                @if($canManageLogin && ! $profile['account']['is_active'] && ! $profile['is_archived'])
                                    <x-slot:footer>
                                        <button class="button button--secondary button--sm" type="button" wire:click="setLogin(true)" wire:loading.attr="data-loading" wire:target="setLogin"><x-ui.icon name="user-check" size="16" />{{ __('manager_customers.actions.login_on') }}</button>
                                    </x-slot:footer>
                                @endif
                            @else
                                <p class="muted crm-note">{{ __('manager_customers.profile.no_account') }}</p>
                            @endif
                        </x-ui.card>
                    </aside>
                </div>
        @endswitch
    </div>

    @include('livewire.center.customers.form')
</div>
