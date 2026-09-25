@php
    $label = fn (array $value) => trim((string) ($value[app()->getLocale()] ?? '')) !== '' ? $value[app()->getLocale()] : ($value['en'] ?? '');
@endphp
<x-ui.card :title="__('sadmin_cms.navigation')">
    <x-slot:actions>
        <button class="button button--secondary button--sm" type="button" wire:click="addNavigation" @disabled(count($content['navigation']) >= 12)><x-ui.icon name="plus" size="16" />{{ __('sadmin_cms.menu.add') }}</button>
    </x-slot:actions>
    <datalist id="cms-anchors">@foreach($anchors as $anchor)<option value="{{ $anchor }}">@endforeach</datalist>
    <div class="cms-item-list">
        @forelse($content['navigation'] as $index => $item)
            @php $name = $label($item['label'] ?? []); @endphp
            <div class="cms-item" wire:key="navigation-{{ $index }}-{{ count($content['navigation']) }}" x-data="{ open: {{ trim((string) ($item['label']['en'] ?? '')) === '' ? 'true' : 'false' }} }" @if(! ($item['enabled'] ?? true)) data-disabled="true" @endif>
                <div class="cms-item__head">
                    <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'">
                        <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
                        <span class="cms-item__title">{{ $name !== '' ? $name : __('sadmin_cms.menu.untitled') }}</span>
                        <span class="cms-item__summary" dir="ltr">{{ ($item['link_type'] ?? 'section') === 'section' ? '#'.($item['target'] ?? '') : ($item['target'] ?? '') }}</span>
                    </button>
                    <div class="cms-item__tools">
                        <button class="icon-button icon-button--sm" type="button" wire:click="moveNavigation({{ $index }}, -1)" @disabled($index === 0) aria-label="{{ __('sadmin_cms.actions.move_up') }}" title="{{ __('sadmin_cms.actions.move_up') }}"><x-ui.icon name="arrow-up" /></button>
                        <button class="icon-button icon-button--sm" type="button" wire:click="moveNavigation({{ $index }}, 1)" @disabled($loop->last) aria-label="{{ __('sadmin_cms.actions.move_down') }}" title="{{ __('sadmin_cms.actions.move_down') }}"><x-ui.icon name="arrow-down" /></button>
                        <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model.live="content.navigation.{{ $index }}.enabled" aria-label="{{ __('sadmin_cms.fields.enabled') }}"></label>
                        <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeNavigation({{ $index }})" wire:confirm="{{ __('sadmin_cms.remove_confirm') }}" data-confirm-tone="danger" aria-label="{{ __('sadmin_cms.actions.remove') }}" title="{{ __('sadmin_cms.actions.remove') }}"><x-ui.icon name="trash" /></button>
                    </div>
                </div>
                <div class="cms-item__body stack stack--sm" x-show="open" x-cloak>
                    <x-ui.lang-tabs :id="'nav-'.$index" primary="en" :values="['content.navigation.'.$index.'.label' => $item['label'] ?? []]" :fields="[
                        ['name' => 'content.navigation.'.$index.'.label', 'label' => __('sadmin_cms.fields.label'), 'max' => 80, 'required' => true],
                    ]" />
                    <div class="cms-grid-3">
                        <div class="field">
                            <label for="nav-{{ $index }}-type">{{ __('sadmin_cms.fields.link_type') }}</label>
                            <select id="nav-{{ $index }}-type" wire:model.live="content.navigation.{{ $index }}.link_type">
                                @foreach(['section', 'internal', 'external'] as $type)
                                    <option value="{{ $type }}">{{ __('sadmin_cms.options.'.$type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field cms-grid-span-2">
                            <label for="nav-{{ $index }}-target">{{ __('sadmin_cms.fields.target') }}</label>
                            @if(($item['link_type'] ?? 'section') === 'section')
                                <select id="nav-{{ $index }}-target" wire:model="content.navigation.{{ $index }}.target" dir="ltr">
                                    @foreach(array_unique([...$anchors, (string) ($item['target'] ?? '')]) as $anchor)
                                        @if($anchor !== '')<option value="{{ $anchor }}">#{{ $anchor }}</option>@endif
                                    @endforeach
                                </select>
                            @else
                                <input id="nav-{{ $index }}-target" dir="ltr" wire:model="content.navigation.{{ $index }}.target" placeholder="{{ ($item['link_type'] ?? '') === 'internal' ? '/register' : 'https://' }}">
                            @endif
                        </div>
                    </div>
                    <div class="cms-grid-2">
                        <div class="field">
                            <label for="nav-{{ $index }}-style">{{ __('sadmin_cms.fields.style') }}</label>
                            <select id="nav-{{ $index }}-style" wire:model="content.navigation.{{ $index }}.style">
                                @foreach(['link', 'primary', 'secondary'] as $style)
                                    <option value="{{ $style }}">{{ __('sadmin_cms.options.'.$style) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <label class="choice"><input type="checkbox" wire:model="content.navigation.{{ $index }}.new_tab"><span>{{ __('sadmin_cms.fields.new_tab') }}</span></label>
                    </div>
                </div>
            </div>
        @empty
            <x-ui.empty-state compact icon="navigation" :title="__('sadmin_cms.menu.empty')" />
        @endforelse
    </div>
</x-ui.card>
