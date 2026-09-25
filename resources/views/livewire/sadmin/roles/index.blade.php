@php
    use App\Livewire\Sadmin\Roles\Index as Roles;

    $kind = $panel !== null ? explode(':', $panel, 2)[0] : null;
    $label = fn (string $code) => __('platform_permissions.codes.'.str_replace('.', '_', $code));
    $readOnly = $kind === 'view';
@endphp

<div class="stack">
    <x-ui.page-header :title="__('sadmin_roles.title')">
        <x-slot:actions>
            <a class="button button--secondary" href="{{ route('superadmin.users.index') }}" wire:navigate><x-ui.icon name="users" size="16" />{{ __('sadmin_roles.users') }}</a>
            <button class="button" type="button" wire:click="openPanel('create')"><x-ui.icon name="plus" size="16" />{{ __('sadmin_roles.create') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    @if($archivedCount > 0 || $archived)
        <div class="segmented" role="group" aria-label="{{ __('sadmin_roles.title') }}">
            <button type="button" wire:click="$set('archived', false)" aria-pressed="{{ $archived ? 'false' : 'true' }}">{{ __('sadmin_roles.current') }}</button>
            <button type="button" wire:click="$set('archived', true)" aria-pressed="{{ $archived ? 'true' : 'false' }}">{{ __('sadmin_roles.archived') }}<span class="segmented__count">{{ $archivedCount }}</span></button>
        </div>
    @endif

    <div class="role-cards">
        @forelse($roles as $role)
            @php $codes = $rolePermissions[$role->id] ?? []; @endphp
            <article class="card role-card" wire:key="role-{{ $role->id }}">
                <header class="role-card__header">
                    <span class="role-card__icon" aria-hidden="true"><x-ui.icon :name="$role->is_system ? 'shield' : 'roles'" /></span>
                    <div>
                        <h2>{{ $role->label() }}</h2>
                        <p class="muted">
                            {{ trans_choice('sadmin_roles.users_count', $role->users_count, ['count' => $role->users_count]) }} ·
                            {{ trans_choice('sadmin_roles.permissions_count', count($codes), ['count' => count($codes)]) }}
                        </p>
                    </div>
                    @if($role->is_system)<span class="badge" data-tone="primary">{{ __('sadmin_roles.system') }}</span>@endif
                </header>
                @if(! empty($role->description[app()->getLocale()] ?? $role->description['en'] ?? ''))
                    <p>{{ $role->description[app()->getLocale()] ?? $role->description['en'] }}</p>
                @endif
                <ul class="chip-list">
                    @foreach(collect(Roles::GROUPS)->filter(fn ($group) => array_intersect($group, $codes) !== []) as $group => $groupCodes)
                        <li class="chip">{{ __('platform_permissions.groups.'.$group) }} <span class="muted">{{ count(array_intersect($groupCodes, $codes)) }}/{{ count($groupCodes) }}</span></li>
                    @endforeach
                </ul>
                <footer class="cluster role-card__actions">
                    @if($role->is_system)
                        <button class="button button--secondary button--sm" type="button" wire:click="openPanel('view:{{ $role->id }}')"><x-ui.icon name="eye" size="16" />{{ __('sadmin_roles.view') }}</button>
                    @elseif($role->archived_at)
                        <button class="button button--secondary button--sm" type="button" wire:click="openPanel('restore:{{ $role->id }}')"><x-ui.icon name="undo" size="16" />{{ __('sadmin_roles.restore') }}</button>
                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="openPanel('delete:{{ $role->id }}')"><x-ui.icon name="trash" size="16" />{{ __('sadmin_roles.delete') }}</button>
                    @else
                        <button class="button button--secondary button--sm" type="button" wire:click="openPanel('edit:{{ $role->id }}')"><x-ui.icon name="edit" size="16" />{{ __('sadmin_roles.edit') }}</button>
                        <a class="button button--ghost button--sm" href="{{ route('superadmin.users.index', ['role' => $role->id]) }}" wire:navigate>{{ __('sadmin_roles.holders') }}</a>
                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="openPanel('archive:{{ $role->id }}')"><x-ui.icon name="archive" size="16" />{{ __('sadmin_roles.archive') }}</button>
                    @endif
                </footer>
            </article>
        @empty
            <x-ui.empty-state icon="roles" :title="__('sadmin_roles.empty')" />
        @endforelse
    </div>

    @if(in_array($kind, ['create', 'edit', 'view'], true))
        <x-ui.drawer :title="$kind === 'create' ? __('sadmin_roles.create') : ($readOnly ? $target?->label() : __('sadmin_roles.edit_title', ['name' => $target?->label()]))" :submit="$readOnly ? null : 'save'" size="lg">
            @if($readOnly)
                <div class="notice" data-tone="info"><x-ui.icon name="shield" /><p>{{ __('sadmin_roles.system_note') }}</p></div>
            @else
                @if($kind === 'create')
                    <x-ui.field :label="__('sadmin_roles.fields.template')" for="role-template" name="template">
                        <select id="role-template" wire:model.live="template">
                            <option value="">{{ __('sadmin_roles.no_template') }}</option>
                            @foreach(array_keys(\App\Kernel\Platform\Identity\Actions\ManagePlatformRoles::TEMPLATES) as $key)
                                <option value="{{ $key }}">{{ __('platform_permissions.templates.'.$key) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.lang-tabs id="role-name" :fields="[
                    ['name' => 'name', 'label' => __('sadmin_roles.fields.name'), 'max' => 190, 'required' => true],
                    ['name' => 'description', 'label' => __('sadmin_roles.fields.description'), 'type' => 'textarea', 'rows' => 2, 'max' => 190],
                ]" :values="['name' => $name, 'description' => $description]" primary="en" />
            @endif

            <fieldset class="field">
                <legend>{{ __('sadmin_roles.fields.permissions') }}</legend>
                <div class="permission-groups">
                    @foreach(Roles::GROUPS as $group => $codes)
                        @php $on = array_intersect($codes, $permissions); @endphp
                        <section class="permission-group" wire:key="perm-group-{{ $group }}">
                            <header class="permission-group__header">
                                <h3>{{ __('platform_permissions.groups.'.$group) }}</h3>
                                @unless($readOnly)
                                    <button class="text-button" type="button" wire:click="toggleGroup('{{ $group }}', {{ count($on) === count($codes) ? 'false' : 'true' }})">{{ count($on) === count($codes) ? __('sadmin_roles.clear_group') : __('sadmin_roles.select_group') }}</button>
                                @endunless
                            </header>
                            @foreach($codes as $code)
                                <label class="choice" wire:key="perm-{{ $code }}">
                                    <input type="checkbox" value="{{ $code }}" wire:model.live="permissions" @disabled($readOnly || (! in_array($code, $held, true) && ! in_array($code, $permissions, true)))>
                                    <span>{{ $label($code) }}
                                        @if(! $readOnly && ! in_array($code, $held, true))<small class="muted">{{ __('sadmin_roles.not_yours') }}</small>@endif
                                    </span>
                                </label>
                            @endforeach
                        </section>
                    @endforeach
                </div>
                @error('permissions')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </fieldset>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ $readOnly ? __('ui.actions.close') : __('ui.actions.cancel') }}</button>
                @unless($readOnly)
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
                @endunless
            </x-slot:footer>
        </x-ui.drawer>
    @elseif($kind && $target)
        <x-ui.modal :title="__('sadmin_roles.confirm.'.$kind.'_title', ['name' => $target->label()])" :description="__('sadmin_roles.confirm.'.$kind.'_body', ['count' => $target->users_count])" :icon="['archive' => 'archive', 'restore' => 'undo', 'delete' => 'trash'][$kind] ?? 'info'" :tone="$kind === 'restore' ? null : 'danger'" submit="confirm">
            @if($kind === 'archive')
                <x-ui.field :label="__('sadmin_roles.fields.reason')" for="role-reason" name="reason" required>
                    <textarea id="role-reason" wire:model="reason" rows="2" required></textarea>
                </x-ui.field>
            @else
                @error('reason')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
            @endif
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closePanel">{{ __('ui.actions.cancel') }}</button>
                <button class="button {{ $kind === 'restore' ? '' : 'button--danger' }}" type="submit" wire:loading.attr="data-loading" wire:target="confirm">{{ __('sadmin_roles.'.$kind) }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
