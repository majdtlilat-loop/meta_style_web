<div class="stack">
    <div class="resources-toolbar">
        <div class="resources-toolbar__filters">
            @if(count($branches) > 1)
                <div class="field">
                    <label for="res-filter-branch">{{ __('ui.fields.branch') }}</label>
                    <select id="res-filter-branch" wire:model.live="filterBranch">
                        <option value="">{{ __('ui.fields.all_branches') }}</option>
                        @foreach($branches as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="field">
                <label for="res-filter-type">{{ __('manager_staff.resources.type') }}</label>
                <select id="res-filter-type" wire:model.live="filterType">
                    <option value="">{{ __('ui.fields.all') }}</option>
                    @foreach($filterTypes as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                </select>
            </div>
            <div class="segmented" role="group" aria-label="{{ __('ui.fields.status') }}">
                <button type="button" wire:click="$set('filterStatus', 'current')" aria-pressed="{{ $filterStatus === 'current' ? 'true' : 'false' }}">{{ __('manager_staff.resources.current') }}</button>
                <button type="button" wire:click="$set('filterStatus', 'archived')" aria-pressed="{{ $filterStatus === 'archived' ? 'true' : 'false' }}">{{ __('ui.states.archived') }}</button>
            </div>
        </div>
        @if($canManage)
            <button class="button" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.resources.add') }}</button>
        @endif
    </div>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    <div class="card card--flush" wire:loading.class="is-refreshing" wire:target="filterBranch,filterType,filterStatus">
        @if($rows === [])
            <x-ui.empty-state icon="resources" :title="$filterStatus === 'archived' ? __('manager_staff.resources.no_archived') : __('manager_staff.resources.empty_title')">
                @if($canManage && $filterStatus !== 'archived' && $formTypes !== [])<button class="button button--sm" type="button" wire:click="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.resources.add') }}</button>@endif
            </x-ui.empty-state>
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <caption class="sr-only">{{ __('manager_staff.resources.tabs.resources') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_staff.fields.name') }}</th>
                            <th scope="col">{{ __('manager_staff.resources.type') }}</th>
                            <th scope="col">{{ __('ui.fields.branch') }}</th>
                            <th scope="col">{{ __('manager_staff.resources.department') }}</th>
                            <th scope="col" class="numeric">{{ __('manager_staff.resources.capacity') }}</th>
                            <th scope="col">{{ __('ui.fields.status') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr wire:key="resource-{{ $row['uuid'] }}" @if($row['state'] !== 'active') data-muted="true" @endif>
                                <td data-label="{{ __('manager_staff.fields.name') }}" data-primary><span class="cell-title">{{ $row['name'] }}</span></td>
                                <td data-label="{{ __('manager_staff.resources.type') }}">{{ $row['type'] }}</td>
                                <td data-label="{{ __('ui.fields.branch') }}">{{ $row['branch'] }}</td>
                                <td data-label="{{ __('manager_staff.resources.department') }}">{{ $row['department'] ?? '—' }}</td>
                                <td data-label="{{ __('manager_staff.resources.capacity') }}" class="numeric tabular">{{ $row['capacity'] }}</td>
                                <td data-label="{{ __('ui.fields.status') }}"><x-ui.status :value="$row['state']" :label="__('ui.states.'.$row['state'])" /></td>
                                <td class="actions">
                                    @if($canManage)
                                        @if($row['state'] === 'archived')
                                            <button class="button button--secondary button--sm" type="button" wire:click="restore('{{ $row['uuid'] }}')"><x-ui.icon name="undo" size="16" />{{ __('ui.actions.restore') }}</button>
                                        @else
                                            <button class="button button--ghost button--sm" type="button" wire:click="edit('{{ $row['uuid'] }}')"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                                            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="archive('{{ $row['uuid'] }}')"
                                                wire:confirm="{{ __('manager_staff.resources.archive_confirm', ['name' => $row['name']]) }}" data-confirm-title="{{ __('manager_staff.resources.archive_title') }}" data-confirm-tone="danger"><x-ui.icon name="archive" size="16" />{{ __('ui.actions.archive') }}</button>
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
        <x-ui.drawer :title="$editing ? __('manager_staff.resources.edit') : __('manager_staff.resources.add')" close="closeForm" submit="save">
            <div class="stack">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                <x-ui.lang-tabs id="resource-name" :locales="$locales" :primary="$primaryLocale" :values="['name' => $name]" :fields="[
                    ['name' => 'name', 'label' => __('manager_staff.fields.name'), 'max' => 190, 'required' => true],
                ]" />
                @error('name')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                @if($formTypes === [])
                    <div class="notice" data-tone="warning"><x-ui.icon name="alert-triangle" /><p>{{ __('manager_staff.resources.no_types') }}</p></div>
                @endif
                <x-ui.field :label="__('manager_staff.resources.type')" for="resource-type" name="type" required>
                    <select id="resource-type" wire:model="type" required>
                        <option value="">{{ __('manager_staff.blocks.choose') }}</option>
                        @foreach($formTypes as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('ui.fields.branch')" for="resource-branch" name="branch" required>
                    <select id="resource-branch" wire:model="branch" required>
                        <option value="">{{ __('manager_staff.blocks.choose') }}</option>
                        @foreach($branches as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('manager_staff.resources.department')" for="resource-department" name="department" :help="__('manager_staff.resources.department_help')">
                    <select id="resource-department" wire:model="department">
                        <option value="">{{ __('ui.states.none') }}</option>
                        @foreach($departments as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                    </select>
                </x-ui.field>
                <div class="form-grid">
                    <x-ui.field :label="__('manager_staff.resources.capacity')" for="resource-capacity" name="capacity" required :help="__('manager_staff.resources.capacity_help')">
                        <input id="resource-capacity" type="number" min="1" max="500" dir="ltr" wire:model="capacity" required>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_staff.branches.sort_order')" for="resource-sort" name="sortOrder">
                        <input id="resource-sort" type="number" min="0" max="9999" dir="ltr" wire:model="sortOrder">
                    </x-ui.field>
                </div>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="isActive"><span>{{ __('manager_staff.resources.active_switch') }}</span></label>
                @if($editing)<p class="field-help">{{ __('manager_staff.resources.edit_note') }}</p>@endif
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
