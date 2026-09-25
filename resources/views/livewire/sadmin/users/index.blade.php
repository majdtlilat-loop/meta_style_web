@php
    $tabs = ['active' => $counts['active'], 'blocked' => $counts['blocked'], 'archived' => $counts['archived'], 'all' => null];
    $kind = $panel !== null ? explode(':', $panel, 2)[0] : null;
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_users.title')">
        <x-slot:actions>
            <a class="button button--secondary" href="{{ route('superadmin.roles.index') }}" wire:navigate><x-ui.icon name="roles" size="16" />{{ __('sadmin_users.manage_roles') }}</a>
            <button class="button" type="button" wire:click="openPanel('invite')"><x-ui.icon name="user-plus" size="16" />{{ __('sadmin_users.invite') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    <div class="segmented segmented--scroll" role="group" aria-label="{{ __('sadmin_users.filters.status') }}">
        @foreach($tabs as $value => $count)
            <button type="button" wire:click="setShow('{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}">
                {{ __('sadmin_users.filters.'.$value) }}
                @if($count !== null)<span class="segmented__count">{{ $count }}</span>@endif
            </button>
        @endforeach
    </div>

    <form class="filter-bar" role="search" x-on:submit.prevent>
        <div class="field filter-bar__search">
            <label for="user-search">{{ __('sadmin_users.filters.search') }}</label>
            <div class="search-input">
                <x-ui.icon name="search" />
                <input id="user-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('sadmin_users.filters.search_placeholder') }}" autocomplete="off">
            </div>
        </div>
        <div class="field">
            <label for="user-role">{{ __('sadmin_users.filters.role') }}</label>
            <select id="user-role" wire:model.live="role">
                <option value="">{{ __('sadmin_users.filters.any_role') }}</option>
                @foreach($roles as $option)
                    <option value="{{ $option->id }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    </form>

    <div class="table-shell table-shell--stack" wire:loading.class="is-refreshing" wire:target="search,show,setShow,role,gotoPage,nextPage,previousPage">
        @if($users->isEmpty())
            <x-ui.empty-state icon="users" :title="__('sadmin_users.empty')" />
        @else
            <table>
                <caption class="sr-only">{{ __('sadmin_users.title') }}</caption>
                <thead><tr>
                    <th scope="col">{{ __('sadmin_users.table.user') }}</th>
                    <th scope="col">{{ __('sadmin_users.table.roles') }}</th>
                    <th scope="col">{{ __('sadmin_users.table.mfa') }}</th>
                    <th scope="col">{{ __('sadmin_users.table.status') }}</th>
                    <th scope="col">{{ __('sadmin_users.table.last_sign_in') }}</th>
                    <th scope="col" class="actions"><span class="sr-only">{{ __('sadmin_users.table.actions') }}</span></th>
                </tr></thead>
                <tbody>
                    @foreach($users as $user)
                        @php
                            $self = $user->is($actor);
                            $state = $user->archived_at ? 'archived' : ($user->is_active ? 'active' : 'blocked');
                        @endphp
                        <tr wire:key="user-{{ $user->uuid }}">
                            <td data-label="{{ __('sadmin_users.table.user') }}" data-primary>
                                <span class="cell-person">
                                    <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                                    <span>
                                        <span class="cell-title">{{ $user->name }}@if($self)<span class="badge">{{ __('sadmin_users.you') }}</span>@endif</span>
                                        <span class="cell-sub" dir="ltr">{{ $emails[$user->uuid] ?? '' }}</span>
                                    </span>
                                </span>
                            </td>
                            <td data-label="{{ __('sadmin_users.table.roles') }}">
                                <span class="chip-list">
                                    @forelse($user->roles as $role)<span class="chip">{{ $role->label() }}</span>@empty<span class="muted">—</span>@endforelse
                                </span>
                            </td>
                            <td data-label="{{ __('sadmin_users.table.mfa') }}">
                                <x-ui.status :value="$user->hasConfirmedMfa() ? 'enabled' : 'pending'" :tone="$user->hasConfirmedMfa() ? 'success' : ($mfaRequired ? 'warning' : 'neutral')" :label="$user->hasConfirmedMfa() ? __('sadmin_users.mfa.on') : __('sadmin_users.mfa.off')" :dot="false" />
                            </td>
                            <td data-label="{{ __('sadmin_users.table.status') }}">
                                <x-ui.status :value="$state" :tone="['active' => 'success', 'blocked' => 'danger', 'archived' => 'neutral'][$state]" :label="__('sadmin_users.filters.'.$state)" />
                            </td>
                            <td data-label="{{ __('sadmin_users.table.last_sign_in') }}">{{ $user->last_login_at?->diffForHumans() ?? __('sadmin_users.never') }}</td>
                            <td class="actions">
                                <button class="button button--secondary button--sm" type="button" wire:click="openPanel('edit:{{ $user->uuid }}')">{{ __('sadmin_users.actions.edit') }}</button>
                                <details class="dropdown" data-popover>
                                    <summary class="icon-button icon-button--sm" aria-label="{{ __('sadmin_users.actions.more', ['name' => $user->name]) }}"><x-ui.icon name="more" /></summary>
                                    <div class="dropdown__panel" role="menu">
                                        @if($state === 'active')
                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('link:{{ $user->uuid }}')"><x-ui.icon name="mail" />{{ __('sadmin_users.actions.link') }}</button>
                                            <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('mfa:{{ $user->uuid }}')"><x-ui.icon name="shield" />{{ __('sadmin_users.actions.mfa') }}</button>
                                        @endif
                                        <a class="menu-item" role="menuitem" href="{{ route('superadmin.audit.index', ['actor' => $user->id]) }}" wire:navigate><x-ui.icon name="history" />{{ __('sadmin_users.actions.activity') }}</a>
                                        @unless($self)
                                            <div class="menu-separator" role="separator"></div>
                                            @if($state === 'active')
                                                <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="openPanel('block:{{ $user->uuid }}')"><x-ui.icon name="user-x" />{{ __('sadmin_users.actions.block') }}</button>
                                            @elseif($state === 'blocked')
                                                <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('unblock:{{ $user->uuid }}')"><x-ui.icon name="user-check" />{{ __('sadmin_users.actions.unblock') }}</button>
                                            @endif
                                            @if($state !== 'archived')
                                                <button class="menu-item menu-item--danger" role="menuitem" type="button" wire:click="openPanel('archive:{{ $user->uuid }}')"><x-ui.icon name="archive" />{{ __('sadmin_users.actions.archive') }}</button>
                                            @else
                                                <button class="menu-item" role="menuitem" type="button" wire:click="openPanel('restore:{{ $user->uuid }}')"><x-ui.icon name="undo" />{{ __('sadmin_users.actions.restore') }}</button>
                                            @endif
                                        @endunless
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    @if($users->hasPages())<div class="table-footer table-footer--bare">{{ $users->links() }}</div>@endif

    @if($kind === 'invite' || $kind === 'edit')
        <x-ui.drawer :title="$kind === 'invite' ? __('sadmin_users.invite') : __('sadmin_users.edit_title', ['name' => $target?->name])" submit="save">
            <x-ui.field :label="__('sadmin_users.fields.name')" for="user-name" name="name" required>
                <input id="user-name" type="text" wire:model="name" maxlength="190" autocomplete="off" required>
            </x-ui.field>
            <x-ui.field :label="__('sadmin_users.fields.email')" for="user-email" name="email" required>
                <input id="user-email" type="email" dir="ltr" wire:model="email" maxlength="190" autocomplete="off" required>
            </x-ui.field>
            <fieldset class="field">
                <legend>{{ __('sadmin_users.fields.roles') }}</legend>
                <div class="stack stack--sm">
                    @foreach($roles as $option)
                        <label class="choice choice--card" wire:key="role-option-{{ $option->id }}">
                            <input type="checkbox" value="{{ $option->id }}" wire:model="roleIds" @disabled($target && $target->is($actor))>
                            <span>
                                <strong>{{ $option->label() }}</strong>
                                @if(! empty($option->description[app()->getLocale()] ?? $option->description['en'] ?? ''))
                                    <small class="field-help">{{ $option->description[app()->getLocale()] ?? $option->description['en'] }}</small>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('roleIds')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </fieldset>
            @if($kind === 'invite')
                <p class="note"><x-ui.icon name="mail" size="16" />{{ __('sadmin_users.invite_note') }}</p>
            @endif
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ $kind === 'invite' ? __('sadmin_users.send_invite') : __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @elseif($kind && $target)
        @php
            $danger = in_array($kind, ['block', 'archive', 'mfa'], true);
            $needsReason = $kind !== 'link';
        @endphp
        <x-ui.modal :title="__('sadmin_users.confirm.'.$kind.'_title', ['name' => $target->name])" :description="__('sadmin_users.confirm.'.$kind.'_body')" :icon="['block' => 'user-x', 'unblock' => 'user-check', 'archive' => 'archive', 'restore' => 'undo', 'mfa' => 'shield', 'link' => 'mail'][$kind] ?? 'info'" :tone="$danger ? 'danger' : null" submit="confirm">
            @if($needsReason)
                <x-ui.field :label="__('sadmin_users.fields.reason')" for="user-reason" name="reason" required>
                    <textarea id="user-reason" wire:model="reason" rows="2" required></textarea>
                </x-ui.field>
            @else
                @error('reason')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
            @endif
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button {{ $danger ? 'button--danger' : '' }}" type="submit" wire:loading.attr="data-loading" wire:target="confirm">{{ __('sadmin_users.actions.'.($kind === 'unblock' ? 'unblock' : $kind)) }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
