<div class="stack staff-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.staff')">
        @if($canView)
            <x-slot:meta>
                <span class="result-count">{{ trans_choice('ui.table.results', $employees->total(), ['count' => $employees->total()]) }}</span>
            </x-slot:meta>
        @endif
        @if($canCreate)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openForm" wire:loading.attr="data-loading" wire:target="openForm"><x-ui.icon name="user-plus" size="16" />{{ __('manager_staff.staff.add') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.notice :message="$notice" dismiss="dismissNotice" />

    @if($activationToken)
        <div class="secret-card staff-activation" role="status" wire:key="activation-{{ md5($activationToken) }}">
            <div class="secret-card__head">
                <span class="secret-card__icon" aria-hidden="true"><x-ui.icon name="key" /></span>
                <div>
                    <strong>{{ __('manager_staff.activation.card_title', ['name' => $activationFor]) }}</strong>
                    <p>{{ __('manager_staff.activation.card_body') }}</p>
                </div>
            </div>
            <div class="copy-field">
                <code dir="ltr">{{ $activationUrl }}</code>
                <button class="button button--secondary button--sm" type="button" data-copy="{{ $activationUrl }}" data-copied="{{ __('ui.actions.copied') }}"><x-ui.icon name="copy" size="16" />{{ __('ui.actions.copy_link') }}</button>
            </div>
            <button class="text-button" type="button" wire:click="dismissToken">{{ __('manager_staff.activation.done') }}</button>
        </div>
    @endif

    @if(! $canView)
        <x-ui.card>
            <x-ui.empty-state icon="lock" :title="__('manager_staff.staff.no_access_title')" :description="__('manager_staff.staff.no_access_body')" />
        </x-ui.card>
    @else
        <div class="stat-strip" aria-label="{{ __('manager_staff.staff.stats_label') }}">
            <div class="stat-strip__item"><span>{{ __('manager_staff.stats.total') }}</span><strong class="tabular">{{ number_format($stats['total']) }}</strong></div>
            <div class="stat-strip__item"><span>{{ __('manager_staff.stats.active') }}</span><strong class="tabular">{{ number_format($stats['active']) }}</strong></div>
            <div class="stat-strip__item"><span>{{ __('manager_staff.stats.login') }}</span><strong class="tabular">{{ number_format($stats['login']) }}</strong></div>
            <div class="stat-strip__item">
                <span>{{ __('manager_staff.stats.pending') }}</span>
                <strong class="tabular">
                    @if($stats['pending'] > 0)
                        <button class="cell-link" type="button" wire:click="$set('login', 'pending')">{{ number_format($stats['pending']) }}</button>
                    @else
                        0
                    @endif
                </strong>
            </div>
        </div>

        <form class="filter-bar" role="search" aria-label="{{ __('manager_staff.filters.label') }}" x-on:submit.prevent data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
            <div class="field filter-bar__search">
                <label for="staff-search">{{ __('ui.fields.search') }}</label>
                <div class="search-input">
                    <x-ui.icon name="search" />
                    <input id="staff-search" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ __('manager_staff.filters.search_placeholder') }}" autocomplete="off">
                </div>
            </div>
            <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="staff-status">
                <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
                @if($activeFilters > 0)<span class="badge">{{ $activeFilters }}</span>@endif
            </button>
            <div class="field">
                <label for="staff-status">{{ __('ui.fields.status') }}</label>
                <select id="staff-status" wire:model.live="status">
                    <option value="">{{ __('ui.fields.all') }}</option>
                    <option value="active">{{ __('ui.states.active') }}</option>
                    <option value="inactive">{{ __('ui.states.inactive') }}</option>
                </select>
            </div>
            @if(count($filterBranches) > 1)
                <div class="field">
                    <label for="staff-branch">{{ __('ui.fields.branch') }}</label>
                    <select id="staff-branch" wire:model.live="branch">
                        <option value="">{{ __('ui.fields.all_branches') }}</option>
                        @foreach($filterBranches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}@if($option['archived']) · {{ __('ui.states.archived') }}@endif</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="field">
                <label for="staff-role">{{ __('manager_staff.fields.role') }}</label>
                <select id="staff-role" wire:model.live="role">
                    <option value="">{{ __('ui.fields.all') }}</option>
                    @foreach($filterRoles as $option)
                        <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="staff-login">{{ __('manager_staff.fields.login') }}</label>
                <select id="staff-login" wire:model.live="login">
                    <option value="">{{ __('ui.fields.all') }}</option>
                    @foreach(['none', 'pending', 'active', 'disabled'] as $state)
                        <option value="{{ $state }}">{{ __('manager_staff.login.'.$state) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-bar__actions">
                @if($activeFilters > 0 || $search !== '')
                    <button class="button button--ghost button--sm" type="button" wire:click="resetFilters">{{ __('ui.actions.clear_filters') }}</button>
                @endif
            </div>
        </form>

        <div class="card card--flush" wire:loading.class="is-refreshing" wire:target="search,status,branch,role,login,resetFilters,gotoPage,nextPage,previousPage">
            @if($employees->isEmpty())
                @if($stats['total'] === 0)
                    <x-ui.empty-state icon="staff" :title="__('manager_staff.staff.empty_title')">
                        @if($canCreate)<button class="button button--sm" type="button" wire:click="openForm"><x-ui.icon name="user-plus" size="16" />{{ __('manager_staff.staff.add') }}</button>@endif
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="search" :title="__('ui.empty.no_results')">
                        <button class="button button--secondary button--sm" type="button" wire:click="resetFilters">{{ __('ui.actions.clear_filters') }}</button>
                    </x-ui.empty-state>
                @endif
            @else
                <div class="table-shell table-shell--stack">
                    <table>
                        <caption class="sr-only">{{ __('ui.manager_nav.items.staff') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('manager_staff.fields.person') }}</th>
                                <th scope="col">{{ __('phone_field.label') }}</th>
                                <th scope="col">{{ __('manager_staff.fields.branches') }}</th>
                                <th scope="col">{{ __('manager_staff.fields.roles') }}</th>
                                <th scope="col">{{ __('manager_staff.fields.login') }}</th>
                                <th scope="col">{{ __('ui.fields.status') }}</th>
                                <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($employees as $row)
                                <tr wire:key="employee-{{ $row['uuid'] }}" @unless($row['active']) data-muted="true" @endunless>
                                    <td data-label="{{ __('manager_staff.fields.person') }}" data-primary>
                                        <span class="cell-person">
                                            <span class="avatar" aria-hidden="true">{{ $row['initial'] }}</span>
                                            <span>
                                                <button class="cell-link cell-title" type="button" wire:click="show('{{ $row['uuid'] }}')">{{ $row['name'] }}</button>
                                                @if($row['is_owner'] || $row['is_self'])
                                                    <span class="chip-list">
                                                        @if($row['is_owner'])<span class="chip">{{ __('manager_staff.badges.owner') }}</span>@endif
                                                        @if($row['is_self'])<span class="chip chip--muted">{{ __('manager_staff.badges.you') }}</span>@endif
                                                    </span>
                                                @endif
                                                @if($row['contact_email'])<span class="cell-sub" dir="ltr">{{ $row['contact_email'] }}</span>@endif
                                            </span>
                                        </span>
                                    </td>
                                    <td data-label="{{ __('phone_field.label') }}">
                                        @if($row['contact_phone'])
                                            <span class="flagged" dir="ltr">@if($row['phone_flag'])<img class="flag-icon" src="{{ $row['phone_flag'] }}" alt="" width="20" height="15" loading="lazy">@endif<span class="tabular">{{ $row['contact_phone'] }}</span></span>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('manager_staff.fields.branches') }}">
                                        @if($row['branches'] === [])
                                            <span class="muted">{{ __('manager_staff.staff.no_branch') }}</span>
                                        @else
                                            <span class="chip-list">@foreach($row['branches'] as $label)<span class="chip">{{ $label }}</span>@endforeach</span>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('manager_staff.fields.roles') }}">
                                        @if($row['roles'] === [])
                                            <span class="muted">—</span>
                                        @else
                                            <span class="chip-list">@foreach($row['roles'] as $label)<span class="chip">{{ $label }}</span>@endforeach</span>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('manager_staff.fields.login') }}">
                                        <x-ui.status :tone="$row['login_tone']" :label="__('manager_staff.login.'.$row['login'])" :dot="false" />
                                    </td>
                                    <td data-label="{{ __('ui.fields.status') }}">
                                        <x-ui.status :value="$row['status']" :label="$row['active'] ? __('ui.states.active') : __('ui.states.inactive')" />
                                    </td>
                                    <td class="actions">
                                        <button class="button button--ghost button--sm" type="button" wire:click="show('{{ $row['uuid'] }}')" wire:loading.attr="data-loading" wire:target="show('{{ $row['uuid'] }}')">{{ __('ui.actions.open') }}<x-ui.icon name="chevron-right" size="14" class="ui-icon--directional" /></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{ $employees->links() }}
    @endif

    @if($showForm && $canCreate)
        <x-ui.drawer :title="__('manager_staff.staff.add')" close="closeForm" submit="create" size="lg">
            <div class="stack">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror

                <section class="drawer-section">
                    <h3>{{ __('manager_staff.sections.details') }}</h3>
                    <x-ui.lang-tabs id="staff-name" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                        ['name' => 'name', 'label' => __('manager_staff.fields.name'), 'max' => 190, 'required' => true],
                    ]" />
                    @error('name')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                </section>

                <section class="drawer-section">
                    <h3>{{ __('manager_staff.fields.branches') }}</h3>
                    @if($formBranches === [])
                        <p class="muted">{{ __('manager_staff.staff.no_branches_available') }}</p>
                    @else
                        <div class="chip-select" role="group" aria-label="{{ __('manager_staff.fields.branches') }}">
                            @foreach($formBranches as $option)
                                <label class="chip-toggle" wire:key="form-branch-{{ $option['id'] }}"><input type="checkbox" value="{{ $option['id'] }}" wire:model="branchIds"><span>{{ $option['name'] }}</span></label>
                            @endforeach
                        </div>
                    @endif
                    @error('branchIds')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                </section>

                <section class="drawer-section">
                    <label class="choice choice--switch staff-login-switch">
                        <input class="switch" type="checkbox" role="switch" wire:model.live="withLogin">
                        <span><strong>{{ __('manager_staff.staff.login_switch') }}</strong></span>
                    </label>

                    @if($withLogin)
                        <div class="stack stack--sm" wire:key="staff-login-fields">
                            <x-ui.phone number="phone" country="phoneCountry" id="staff-phone" :label="__('phone_field.login_label')" required />
                            <x-ui.field :label="__('manager_staff.fields.email_optional')" for="staff-email" name="email" :help="__('manager_staff.staff.email_help')">
                                <input id="staff-email" type="email" dir="ltr" wire:model="email" autocomplete="off" maxlength="190">
                            </x-ui.field>
                            @if($canAssignRoles)
                                <fieldset class="field">
                                    <legend>{{ __('manager_staff.fields.roles') }}</legend>
                                    @if($formRoles === [])
                                        <p class="muted">{{ __('manager_staff.staff.no_roles_available') }}</p>
                                    @else
                                        <div class="chip-select">
                                            @foreach($formRoles as $option)
                                                <label class="chip-toggle" wire:key="form-role-{{ $option['id'] }}"><input type="checkbox" value="{{ $option['id'] }}" wire:model="roleIds"><span>{{ $option['name'] }}</span></label>
                                            @endforeach
                                        </div>
                                        <p class="field-help">{{ __('manager_staff.staff.roles_help') }}</p>
                                    @endif
                                    @error('roleIds')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                                </fieldset>
                            @endif
                            <div class="notice" data-tone="info"><x-ui.icon name="key" /><p>{{ __('manager_staff.staff.activation_note') }}</p></div>
                        </div>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="create">{{ __('manager_staff.staff.add') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif

    @if($open !== '')
        <livewire:center.staff.profile :uuid="$open" :key="'staff-profile-'.$open" />
    @endif
</div>
