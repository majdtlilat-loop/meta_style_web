<div class="stack availability-blocks">
    @if(! $canView)
        <x-ui.empty-state compact icon="lock" :title="__('manager_staff.blocks.no_access')" />
    @else
        @if(($employee === '' && ($employees !== [] || count($filterBranches) > 1)) || $canManage)
            <div class="availability-blocks__toolbar">
                @if($employee === '' && ($employees !== [] || count($filterBranches) > 1))
                    <div class="availability-blocks__filters">
                        @if($employees !== [])
                            <div class="field">
                                <label for="blocks-filter-employee">{{ __('manager_staff.blocks.employee') }}</label>
                                <select id="blocks-filter-employee" wire:model.live="filterEmployee">
                                    <option value="">{{ __('ui.fields.all') }}</option>
                                    @foreach($employees as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                                </select>
                            </div>
                        @endif
                        @if(count($filterBranches) > 1)
                            <div class="field">
                                <label for="blocks-filter-branch">{{ __('ui.fields.branch') }}</label>
                                <select id="blocks-filter-branch" wire:model.live="filterBranch">
                                    <option value="">{{ __('ui.fields.all_branches') }}</option>
                                    @foreach($filterBranches as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                @endif
                @if($canManage)
                    <button class="button button--sm" type="button" wire:click="create" wire:loading.attr="data-loading" wire:target="create"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.blocks.add') }}</button>
                @endif
            </div>
        @endif

        <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

        @if($affected !== [])
            <div class="notice" data-tone="warning" role="status">
                <x-ui.icon name="alert-triangle" />
                <div>
                    <p><strong>{{ __('manager_staff.blocks.affected_title') }}</strong> {{ __('manager_staff.blocks.affected_body') }}</p>
                    <ul class="availability-blocks__affected">
                        @foreach($affected as $appointment)
                            <li wire:key="affected-{{ $appointment['uuid'] }}"><span class="tabular">{{ $appointment['at'] }}</span>@if($appointment['customer']) · {{ $appointment['customer'] }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @if($blocks === [])
            <x-ui.empty-state compact icon="clock" :title="__('manager_staff.blocks.empty_title')" />
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <caption class="sr-only">{{ __('manager_staff.blocks.title') }}</caption>
                    <thead>
                        <tr>
                            @if($employee === '')<th scope="col">{{ __('manager_staff.blocks.employee') }}</th>@endif
                            <th scope="col">{{ __('manager_staff.blocks.when') }}</th>
                            <th scope="col">{{ __('ui.fields.branch') }}</th>
                            <th scope="col">{{ __('ui.fields.reason') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($blocks as $row)
                            <tr wire:key="block-{{ $row['uuid'] }}">
                                @if($employee === '')<td data-label="{{ __('manager_staff.blocks.employee') }}" data-primary><span class="cell-title">{{ $row['employee'] }}</span></td>@endif
                                <td data-label="{{ __('manager_staff.blocks.when') }}" @if($employee !== '') data-primary @endif>
                                    <span class="cell-title">{{ $row['date'] }}</span>
                                    <span class="cell-sub tabular" dir="ltr">{{ $row['from'] }} – {{ $row['to'] }}</span>
                                </td>
                                <td data-label="{{ __('ui.fields.branch') }}">{{ $row['branch'] }}</td>
                                <td data-label="{{ __('ui.fields.reason') }}">
                                    <span class="chip" data-block-type="{{ $row['type'] }}">{{ $row['type_label'] }}</span>
                                    @if($row['note'])<span class="cell-sub clamp-2">{{ $row['note'] }}</span>@endif
                                </td>
                                <td class="actions">
                                    @if($row['can_manage'])
                                        <button class="button button--ghost button--sm" type="button" wire:click="edit('{{ $row['uuid'] }}')"><x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}</button>
                                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="delete('{{ $row['uuid'] }}')"
                                            wire:confirm="{{ __('manager_staff.blocks.remove_confirm') }}" data-confirm-title="{{ __('manager_staff.blocks.remove') }}" data-confirm-tone="danger"><x-ui.icon name="trash" size="16" />{{ __('ui.actions.remove') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($showForm && $canManage)
            <x-ui.modal close="closeForm" submit="save" size="lg" icon="clock"
                :title="$editing ? __('manager_staff.blocks.edit') : __('manager_staff.blocks.add')"
                :description="__('manager_staff.blocks.form_help')">
                <div class="stack stack--sm availability-blocks__form">
                    @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                    @if($employee === '')
                        <x-ui.field :label="__('manager_staff.blocks.employee')" for="block-employee" name="formEmployee" required>
                            <select id="block-employee" wire:model.live="formEmployee" required>
                                <option value="">{{ __('manager_staff.blocks.choose') }}</option>
                                @foreach($employees as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                            </select>
                        </x-ui.field>
                    @endif
                    <x-ui.field :label="__('ui.fields.branch')" for="block-branch" name="formBranch" required :help="__('manager_staff.blocks.branch_help')">
                        <select id="block-branch" wire:model="formBranch" required>
                            <option value="">{{ __('manager_staff.blocks.choose') }}</option>
                            @foreach($formBranches as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <div class="form-grid">
                        <x-ui.field :label="__('ui.fields.from')" for="block-from" name="startsAt" required>
                            <input id="block-from" type="datetime-local" dir="ltr" wire:model="startsAt" required>
                        </x-ui.field>
                        <x-ui.field :label="__('ui.fields.to')" for="block-to" name="endsAt" required>
                            <input id="block-to" type="datetime-local" dir="ltr" wire:model="endsAt" required>
                        </x-ui.field>
                    </div>
                    <x-ui.field :label="__('ui.fields.reason')" for="block-type" name="type" required>
                        <select id="block-type" wire:model="type">
                            @foreach($types as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_staff.blocks.note')" for="block-note" name="note" :help="$noteAdvisory">
                        <textarea id="block-note" rows="2" wire:model="note" maxlength="2000"></textarea>
                    </x-ui.field>
                </div>
                <x-slot:footer>
                    <button class="button button--secondary" type="button" wire:click="closeForm">{{ __('ui.actions.cancel') }}</button>
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif
</div>
