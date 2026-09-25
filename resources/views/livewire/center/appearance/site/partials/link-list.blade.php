{{--
    A reorderable list of links (menu, footer links, legal links).
    Params: $list (content path under `content.`), $items, $max, $title, $description.
--}}
<x-ui.card :title="$title" :description="$description ?? null">
    <x-slot:actions>
        @if($canManage)
            <button class="button button--secondary button--sm" type="button" wire:click="addItem('{{ $list }}')" @disabled(count($items) >= $max)><x-ui.icon name="plus" size="16" />{{ __('manager_site.actions.add_link') }}</button>
        @endif
    </x-slot:actions>
    @if($items === [])
        <x-ui.empty-state compact icon="navigation" :title="__('manager_site.empty.links')" />
    @else
        <ul class="cms-item-list sb-list" @if($canManage) x-data x-sortable="sortItems" data-sortable-group="{{ $list }}" @endif>
            @foreach($items as $index => $item)
                <li class="cms-item" data-sortable-item="{{ $list }}|{{ $item['id'] }}" wire:key="{{ $list }}-{{ $item['id'] }}" x-data="{ open: {{ ($item['label'][$primary] ?? '') === '' ? 'true' : 'false' }} }" @if(! ($item['enabled'] ?? true)) data-disabled="true" @endif>
                    <div class="cms-item__head">
                        @if($canManage)
                            <button type="button" class="icon-button icon-button--sm sb-grip" data-sortable-handle aria-label="{{ __('manager_site.actions.drag', ['name' => ($item['label'][$primary] ?? '') ?: __('manager_site.untitled')]) }}"><x-ui.icon name="grip" /></button>
                        @endif
                        <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'">
                            <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
                            <span class="cms-item__title">{{ (($item['label'][app()->getLocale()] ?? '') ?: ($item['label'][$primary] ?? '')) ?: __('manager_site.untitled') }}</span>
                            <span class="cms-item__summary" dir="ltr">{{ ($item['link_type'] ?? '') === 'section' ? '#'.($item['target'] ?? '') : (($item['link_type'] ?? '') === 'page' ? ($choices['pages'][$item['target'] ?? ''] ?? '') : ($item['target'] ?? '')) }}</span>
                        </button>
                        <div class="cms-item__tools">
                            @if($canManage)
                                <button class="icon-button icon-button--sm" type="button" wire:click="moveItem('{{ $list }}', '{{ $item['id'] }}', -1)" @disabled($loop->first) aria-label="{{ __('manager_site.actions.move_up') }}" title="{{ __('manager_site.actions.move_up') }}"><x-ui.icon name="arrow-up" /></button>
                                <button class="icon-button icon-button--sm" type="button" wire:click="moveItem('{{ $list }}', '{{ $item['id'] }}', 1)" @disabled($loop->last) aria-label="{{ __('manager_site.actions.move_down') }}" title="{{ __('manager_site.actions.move_down') }}"><x-ui.icon name="arrow-down" /></button>
                            @endif
                            <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model.live="content.{{ $list }}.{{ $index }}.enabled" aria-label="{{ __('manager_site.fields.enabled') }}"></label>
                            @if($canManage)
                                <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeItem('{{ $list }}', '{{ $item['id'] }}')" wire:confirm="{{ __('manager_site.confirm.remove_link') }}" data-confirm-title="{{ __('manager_site.confirm.remove_title') }}" data-confirm-tone="danger" aria-label="{{ __('manager_site.actions.remove') }}" title="{{ __('manager_site.actions.remove') }}"><x-ui.icon name="trash" /></button>
                            @endif
                        </div>
                    </div>
                    <div class="cms-item__body stack stack--sm" x-show="open" x-cloak>
                        <x-ui.lang-tabs :id="'link-'.$item['id']" :locales="$locales" :primary="$primary" :values="['content.'.$list.'.'.$index.'.label' => $item['label'] ?? []]" :fields="[
                            ['name' => 'content.'.$list.'.'.$index.'.label', 'label' => __('manager_site.fields.label'), 'max' => $choices['limits']['label'], 'required' => true],
                        ]" />
                        <div class="cms-grid-2">
                            <x-ui.field :label="__('manager_site.fields.link_type')" :for="'link-'.$item['id'].'-type'" :name="'content.'.$list.'.'.$index.'.link_type'">
                                <select id="link-{{ $item['id'] }}-type" wire:model.live="content.{{ $list }}.{{ $index }}.link_type">
                                    @foreach($choices['link_types'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            @include('livewire.center.appearance.site.partials.target', ['path' => 'content.'.$list.'.'.$index, 'type' => $item['link_type'] ?? 'section', 'value' => (string) ($item['target'] ?? ''), 'domId' => 'link-'.$item['id']])
                        </div>
                        @if(($item['link_type'] ?? 'section') !== 'section')
                            <label class="choice"><input type="checkbox" wire:model="content.{{ $list }}.{{ $index }}.new_tab"><span>{{ __('manager_site.fields.new_tab') }}</span></label>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
    @error('content.'.$list)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</x-ui.card>
