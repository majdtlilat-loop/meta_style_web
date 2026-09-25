<div class="stack">
    @if($archivedCount > 0 || $canManage)
        <div class="resources-toolbar">
            <div class="cluster">
                @if($archivedCount > 0)
                    <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="showArchived"><span>{{ __('manager_staff.resources.show_archived', ['count' => $archivedCount]) }}</span></label>
                @endif
                @if($canManage)
                    <button class="button" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.resources.add_type') }}</button>
                @endif
            </div>
        </div>
    @endif

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    <div class="card card--flush">
        @if($rows === [])
            <x-ui.empty-state icon="layers" :title="__('manager_staff.resources.types_empty_title')">
                @if($canManage)<button class="button button--sm" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.resources.add_type') }}</button>@endif
            </x-ui.empty-state>
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <caption class="sr-only">{{ __('manager_staff.resources.tabs.types') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_staff.fields.name') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_staff.resources.tabs.resources') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_staff.resources.required_by') }}</th>
                            <th scope="col">{{ __('ui.fields.status') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr wire:key="type-{{ $row['uuid'] }}" @if($row['state'] !== 'active') data-muted="true" @endif>
                                <td data-label="{{ __('manager_staff.fields.name') }}" data-primary>
                                    <span class="cell-title">{{ $row['name'] }}</span>
                                    @if($row['description'])<span class="cell-sub clamp-2">{{ $row['description'] }}</span>@endif
                                </td>
                                <td data-label="{{ __('manager_staff.resources.tabs.resources') }}" class="numeric tabular">{{ $row['resources'] }}</td>
                                <td data-label="{{ __('manager_staff.resources.required_by') }}" class="numeric tabular">{{ trans_choice('manager_staff.resources.services_count', $row['services'], ['count' => $row['services']]) }}</td>
                                <td data-label="{{ __('ui.fields.status') }}"><x-ui.status :value="$row['state']" :label="__('ui.states.'.$row['state'])" /></td>
                                <td class="actions">
                                    @if($canManage)
                                        @if($row['state'] === 'archived')
                                            <button class="button button--secondary button--sm" type="button" wire:click="restore('{{ $row['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('ui.actions.restore') }}</button>
                                        @else
                                            <button class="button button--ghost button--sm" type="button" wire:click="edit('{{ $row['uuid'] }}')"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                                            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="archive('{{ $row['uuid'] }}')"
                                                wire:confirm="{{ __('manager_staff.resources.type_archive_confirm', ['name' => $row['name']]) }}" data-confirm-title="{{ __('manager_staff.resources.type_archive_title') }}" data-confirm-tone="danger"><x-ui.icon name="archive" size="16" />{{ __('ui.actions.archive') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if($showForm && $canManage)
        <x-ui.drawer :title="$editing ? __('manager_staff.resources.edit_type') : __('manager_staff.resources.add_type')" close="closeForm" submit="save">
            <div class="stack">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                <x-ui.lang-tabs id="type-text" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name, 'description' => $description]" :fields="[
                    ['name' => 'name', 'label' => __('manager_staff.fields.name'), 'max' => 190, 'required' => true],
                    ['name' => 'description', 'label' => __('manager_staff.resources.description'), 'type' => 'textarea', 'rows' => 2, 'max' => 500],
                ]" />
                <x-ui.field :label="__('manager_staff.branches.sort_order')" for="type-sort" name="sortOrder">
                    <input id="type-sort" type="number" min="0" max="9999" dir="ltr" wire:model="sortOrder">
                </x-ui.field>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="isActive"><span>{{ __('manager_staff.resources.type_active_switch') }}</span></label>
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
