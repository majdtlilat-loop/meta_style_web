<div class="stack">
    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    <div class="card card--flush">
        @if($services === [])
            <x-ui.empty-state icon="scissors" :title="__('manager_staff.services.empty_title')" :description="__('manager_staff.services.empty_body')" />
        @else
            <div class="table-shell table-shell--stack">
                <table>
                    <caption class="sr-only">{{ __('manager_staff.resources.tabs.requirements') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('manager_staff.resources.service') }}</th>
                            <th scope="col">{{ __('manager_staff.resources.needs') }}</th>
                            <th scope="col" class="actions"><span class="sr-only">{{ __('ui.table.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($services as $row)
                            <tr wire:key="req-{{ $row['uuid'] }}" @unless($row['active']) data-muted="true" @endunless>
                                <td data-label="{{ __('manager_staff.resources.service') }}" data-primary><span class="cell-title">{{ $row['name'] }}</span></td>
                                <td data-label="{{ __('manager_staff.resources.needs') }}">
                                    @if($row['needs'] === [])
                                        <span class="muted">{{ __('manager_staff.resources.needs_nothing') }}</span>
                                    @else
                                        <span class="chip-list">@foreach($row['needs'] as $need)<span class="chip">{{ $need }}</span>@endforeach</span>
                                    @endif
                                </td>
                                <td class="actions">
                                    <button class="button button--ghost button--sm" type="button" wire:click="open('{{ $row['uuid'] }}')">
                                        @if($canManage)<x-ui.icon name="edit" size="16" />{{ __('ui.actions.edit') }}@else<x-ui.icon name="eye" size="16" />{{ __('ui.actions.view') }}@endif
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if($service !== null && $serviceName !== null)
        <x-ui.drawer :title="$serviceName" close="close" :submit="$canManage ? 'save' : null">
            <div class="stack">
                @error('form')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                @if($types === [])
                    <div class="notice" data-tone="warning"><x-ui.icon name="alert-triangle" /><p>{{ __('manager_staff.resources.no_types') }}</p></div>
                @endif
                <ul class="requirement-rows" role="list">
                    @foreach($rows as $index => $row)
                        <li class="requirement-rows__row" wire:key="req-row-{{ $index }}">
                            <x-ui.field :label="__('manager_staff.resources.type')" for="req-type-{{ $index }}">
                                <select id="req-type-{{ $index }}" wire:model="rows.{{ $index }}.type" @disabled(! $canManage)>
                                    <option value="">{{ __('manager_staff.blocks.choose') }}</option>
                                    @foreach($types as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('manager_staff.resources.quantity')" for="req-qty-{{ $index }}" name="rows.{{ $index }}.quantity">
                                <input id="req-qty-{{ $index }}" type="number" min="1" max="255" dir="ltr" wire:model="rows.{{ $index }}.quantity" @disabled(! $canManage)>
                            </x-ui.field>
                            @if($canManage)
                                <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeRow({{ $index }})" aria-label="{{ __('ui.actions.remove') }}" title="{{ __('ui.actions.remove') }}"><x-ui.icon name="trash" /></button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($canManage)
                    <button class="button button--secondary button--sm requirement-rows__add" type="button" wire:click="addRow"><x-ui.icon name="plus" size="16" />{{ __('manager_staff.resources.add_requirement') }}</button>
                @endif
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="close">{{ $canManage ? __('ui.actions.cancel') : __('ui.actions.close') }}</button>
                @if($canManage)
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
