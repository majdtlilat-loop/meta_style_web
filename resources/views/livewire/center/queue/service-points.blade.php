{{--
    Service desks — where a number is called TO. docs/17-QUEUE.md §8.
    Archive, never delete: tickets already sent to a desk keep pointing at it.
--}}
<div>
    <x-ui.card :title="__('manager_queue.setup.points_title')" flush>
        <x-slot:actions>
            <div class="segmented segmented--sm" role="group" aria-label="{{ __('ui.fields.status') }}">
                <button type="button" wire:click="$set('archived', false)" aria-pressed="{{ $archived ? 'false' : 'true' }}">{{ __('ui.states.active') }}</button>
                <button type="button" wire:click="$set('archived', true)" aria-pressed="{{ $archived ? 'true' : 'false' }}">{{ __('ui.states.archived') }}</button>
            </div>
            @if($entitled && ! $archived)
                <x-ui.button size="sm" icon="plus" wire:click="create">{{ __('manager_queue.setup.add_point') }}</x-ui.button>
            @endif
        </x-slot:actions>

        @if($notice !== '' && $editing === '')
            <div class="card__body">
                <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />
            </div>
        @endif

        @if($points === [])
            <x-ui.empty-state icon="map-pin" compact :title="$archived ? __('manager_queue.setup.no_archived_points') : __('manager_queue.setup.no_points')">
                @if($entitled && ! $archived)
                    <x-ui.button size="sm" icon="plus" wire:click="create">{{ __('manager_queue.setup.add_point') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <caption class="sr-only">{{ __('manager_queue.setup.points_title') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_queue.setup.code') }}</th>
                            <th scope="col">{{ __('manager_queue.setup.point_name') }}</th>
                            <th scope="col">{{ __('ui.fields.branch') }}</th>
                            <th scope="col">{{ __('manager_queue.setup.department') }}</th>
                            <th scope="col">{{ __('manager_queue.setup.prefix') }}</th>
                            <th scope="col">{{ __('ui.fields.status') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($points as $point)
                            <tr wire:key="point-{{ $point['uuid'] }}" @if(! $point['is_active']) data-muted="true" @endif>
                                <td data-label="{{ __('manager_queue.setup.code') }}" data-primary><span class="queue-code" dir="ltr">{{ $point['code'] }}</span></td>
                                <td data-label="{{ __('manager_queue.setup.point_name') }}">
                                    <span class="cell-title">{{ $point['name'] }}</span>
                                    @if($point['resource'])<span class="cell-sub"><x-ui.icon name="resources" size="12" /> {{ $point['resource'] }}</span>@endif
                                </td>
                                <td data-label="{{ __('ui.fields.branch') }}">{{ $point['branch'] ?? '—' }}</td>
                                <td data-label="{{ __('manager_queue.setup.department') }}">{{ $point['department'] ?? '—' }}</td>
                                <td data-label="{{ __('manager_queue.setup.prefix') }}"><span dir="ltr">{{ $point['prefix'] ?? '—' }}</span></td>
                                <td data-label="{{ __('ui.fields.status') }}">
                                    <x-ui.status :value="$point['archived'] ? 'archived' : ($point['is_active'] ? 'active' : 'inactive')" :label="$point['archived'] ? __('ui.states.archived') : ($point['is_active'] ? __('ui.states.active') : __('ui.states.inactive'))" />
                                </td>
                                <td class="actions">
                                    @if($entitled && ! $point['archived'])
                                        <x-ui.button size="sm" variant="secondary" icon="edit" wire:click="edit('{{ $point['uuid'] }}')">{{ __('ui.actions.edit') }}</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" icon="archive" wire:click="archive('{{ $point['uuid'] }}')"
                                            wire:confirm="{{ __('manager_queue.setup.archive_point_confirm', ['code' => $point['code']]) }}"
                                            data-confirm-title="{{ __('manager_queue.setup.archive_point') }}" data-confirm-tone="danger">{{ __('ui.actions.archive') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($editing !== '')
        <x-ui.drawer :title="$editing === 'new' ? __('manager_queue.setup.add_point') : __('manager_queue.setup.edit_point')" close="closePanel" submit="save">
            <div class="stack">
                <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

                <x-ui.field :label="__('ui.fields.branch')" for="point-branch" name="branch" required>
                    <select id="point-branch" wire:model.live="branch" @disabled($editing !== 'new')>
                        @foreach($branches as $option)
                            <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <x-ui.lang-tabs id="point-name" :locales="$locales" :primary="$primary" :values="['names' => $names]"
                    :fields="[['name' => 'names', 'label' => __('manager_queue.setup.point_name'), 'max' => 120, 'required' => true]]" />

                <div class="form-grid">
                    <x-ui.field :label="__('manager_queue.setup.code')" for="point-code" name="code" required :help="__('manager_queue.setup.code_help')">
                        <input id="point-code" type="text" wire:model="code" maxlength="8" dir="ltr" autocomplete="off" class="queue-code-input">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_queue.setup.prefix')" for="point-prefix" name="prefix" :help="__('manager_queue.setup.prefix_help')">
                        <input id="point-prefix" type="text" wire:model="prefix" maxlength="4" dir="ltr" autocomplete="off" class="queue-code-input">
                    </x-ui.field>
                    <x-ui.field :label="__('manager_queue.setup.department')" for="point-department">
                        <select id="point-department" wire:model="department">
                            <option value="">{{ __('manager_queue.setup.any_department') }}</option>
                            @foreach($departments as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_queue.setup.resource')" for="point-resource" :help="__('manager_queue.setup.resource_help')">
                        <select id="point-resource" wire:model="resource">
                            <option value="">{{ __('manager_queue.setup.no_resource') }}</option>
                            @foreach($resources as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_queue.setup.sort')" for="point-sort" name="sort">
                        <input id="point-sort" type="number" min="0" max="9999" wire:model="sort">
                    </x-ui.field>
                </div>

                <label class="check-row">
                    <input type="checkbox" wire:model="active">
                    <span>{{ __('manager_queue.setup.point_active') }}</span>
                </label>
            </div>

            <x-slot:footer>
                <span class="drawer__spacer"></span>
                <x-ui.button variant="ghost" wire:click="closePanel">{{ __('ui.actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
