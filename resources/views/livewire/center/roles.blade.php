<div class="stack roles-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.roles')">
        @if($canView && $canCreate)
            <x-slot:actions>
                <button class="button" type="button" wire:click="openCreate"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.roles.create') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.notice :message="$notice" dismiss="dismissNotice" />

    @if(! $canView)
        <x-ui.card>
            <x-ui.empty-state icon="lock" :title="__('manager_staff.roles.no_access_title')" :description="__('manager_staff.roles.no_access_body')" />
        </x-ui.card>
    @elseif($roles === [])
        <x-ui.card><x-ui.empty-state icon="roles" :title="__('manager_staff.roles.empty')" /></x-ui.card>
    @else
        <div class="role-grid">
            @foreach($roles as $role)
                <article class="role-card" wire:key="role-{{ $role['uuid'] }}">
                    <div class="role-card__head">
                        <span class="role-card__icon" aria-hidden="true"><x-ui.icon :name="$role['system'] ? 'shield' : 'roles'" /></span>
                        <div>
                            <h2>{{ $role['name'] }}</h2>
                            <span class="cell-sub">{{ trans_choice('manager_staff.roles.members', $role['members'], ['count' => $role['members']]) }} · {{ trans_choice('permissions.count', $role['permissions'], ['count' => $role['permissions']]) }}</span>
                        </div>
                        @if($role['owner'])
                            <x-ui.status tone="primary" :label="__('manager_staff.roles.owner_badge')" :dot="false" />
                        @elseif($role['system'])
                            <x-ui.status tone="neutral" :label="__('manager_staff.roles.system_badge')" :dot="false" />
                        @else
                            <x-ui.status tone="info" :label="__('manager_staff.roles.custom_badge')" :dot="false" />
                        @endif
                    </div>
                    @if($role['groups'] === [])
                        <p class="muted">{{ __('manager_staff.roles.no_permissions') }}</p>
                    @else
                        <ul class="chip-list roles-page__groups">
                            @foreach(array_slice($role['groups'], 0, 6) as $group)
                                <li class="chip">{{ $group['label'] }} <span class="muted tabular">{{ $group['count'] }}/{{ $group['total'] }}</span></li>
                            @endforeach
                            @if(count($role['groups']) > 6)
                                <li class="chip chip--muted">{{ __('manager_staff.roles.more_groups', ['count' => count($role['groups']) - 6]) }}</li>
                            @endif
                        </ul>
                    @endif
                    <div class="role-card__foot">
                        @if(! $role['system'] && $canDelete)
                            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="openDelete('{{ $role['uuid'] }}')"><x-ui.icon name="trash" size="16" />{{ __('ui.actions.delete') }}</button>
                        @endif
                        @if(! $role['system'] && $canRename)
                            <button class="button button--ghost button--sm" type="button" wire:click="openRename('{{ $role['uuid'] }}')"><x-ui.icon name="type" size="16" />{{ __('manager_staff.roles.rename') }}</button>
                        @endif
                        <button class="button button--secondary button--sm" type="button" wire:click="openRole('{{ $role['uuid'] }}')">
                            @if($canManage && ! $role['owner'])
                                <x-ui.icon name="edit" size="16" />{{ __('manager_staff.roles.edit_permissions') }}
                            @else
                                <x-ui.icon name="eye" size="16" />{{ __('manager_staff.roles.view_permissions') }}
                            @endif
                        </button>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if(in_array($panel, ['create', 'edit', 'view'], true))
        <x-ui.drawer size="lg" close="closePanel" :submit="$panel === 'view' ? null : 'save'"
            :title="$panel === 'create' ? __('manager_staff.roles.create') : ($targetRole['name'] ?? '')"
            :description="trans_choice('permissions.count', count($selected), ['count' => count($selected)])">
            <div class="stack">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror

                @if($panel === 'view')
                    <div class="notice" data-tone="info"><x-ui.icon name="shield" /><p>{{ ($targetRole['owner'] ?? false) ? __('manager_staff.roles.owner_read_only') : __('manager_staff.roles.read_only') }}</p></div>
                @elseif($panel === 'create')
                    <x-ui.lang-tabs id="role-name" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                        ['name' => 'name', 'label' => __('manager_staff.roles.name'), 'max' => 190, 'required' => true],
                    ]" />
                    @error('name')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    @unless($canManage)
                        <p class="field-help">{{ __('manager_staff.roles.create_without_permissions') }}</p>
                    @endunless
                @endif

                @if($panel !== 'create' || $canManage)
                    <div class="permission-groups">
                        @foreach($groups as $group)
                            <fieldset class="permission-group" wire:key="group-{{ $group['key'] }}">
                                <legend class="permission-group__head">
                                    <span>{{ $group['label'] }}</span>
                                    <span class="muted tabular">{{ $group['chosen'] }}/{{ $group['total'] }}</span>
                                    @if($panel !== 'view' && $group['changeable'] > 0)
                                        <button class="text-button" type="button" wire:click="toggleGroup('{{ $group['key'] }}', {{ $group['all_on'] ? 'false' : 'true' }})">{{ $group['all_on'] ? __('ui.actions.clear') : __('manager_staff.roles.select_group') }}</button>
                                    @endif
                                </legend>
                                <div class="permission-group__items">
                                    @foreach($group['codes'] as $permission)
                                        <label class="choice" wire:key="perm-{{ $permission['code'] }}" @unless($permission['held'] || $panel === 'view') title="{{ __('manager_staff.roles.not_held') }}" @endunless>
                                            <input type="checkbox" value="{{ $permission['code'] }}" wire:model.live="selected" @disabled($panel === 'view' || ! $permission['held'])>
                                            <span>{{ $permission['label'] }}
                                                @if($panel !== 'view' && ! $permission['held'])<small class="muted">{{ __('manager_staff.roles.not_held') }}</small>@endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                @endif
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ $panel === 'view' ? __('ui.actions.close') : __('ui.actions.cancel') }}</button>
                @if($panel !== 'view')
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ $panel === 'create' ? __('manager_staff.roles.create') : __('manager_staff.roles.save_permissions') }}</button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @elseif($panel === 'rename' && $targetRole)
        <x-ui.modal close="closePanel" submit="rename" icon="type" size="lg" :title="__('manager_staff.roles.rename_title', ['name' => $targetRole['name']])">
            @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
            <x-ui.lang-tabs id="role-rename" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                ['name' => 'name', 'label' => __('manager_staff.roles.name'), 'max' => 190, 'required' => true],
            ]" />
            @error('name')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="rename">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif($panel === 'delete' && $targetRole)
        <x-ui.modal close="closePanel" submit="delete" icon="trash" tone="danger"
            :title="__('manager_staff.roles.delete_title', ['name' => $targetRole['name']])"
            :description="$targetRole['members'] > 0 ? trans_choice('manager_staff.roles.delete_blocked', $targetRole['members'], ['count' => $targetRole['members']]) : __('manager_staff.roles.delete_body')">
            @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                @if($targetRole['members'] === 0)
                    <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="delete">{{ __('ui.actions.delete') }}</button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
