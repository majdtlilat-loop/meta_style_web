{{--
    A section's repeatable items: highlights (icon, title, text), questions
    and answers, gallery images, or team members (a real, active employee with
    a job title and photo written for the site).
    Params: $id (section id), $section, $kind, $max.
--}}
<x-ui.card :title="__('manager_site.items.'.$kind.'.title')">
    <x-slot:actions>
        @if($kind === 'team')
            <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_site.items.team.help') }}" aria-label="{{ __('manager_site.items.team.help') }}"><x-ui.icon name="info" size="16" /></span>
        @endif
        @if($canManage && $kind !== 'gallery')
            <button class="button button--secondary button--sm" type="button" wire:click="addItem('sections.{{ $id }}.items')" @disabled(count($section['items']) >= $max)><x-ui.icon name="plus" size="16" />{{ __('manager_site.items.'.$kind.'.add') }}</button>
        @endif
    </x-slot:actions>
    <div class="stack">
        @if($kind === 'gallery' && $canManage && count($section['items']) < $max)
            <livewire:center.appearance.site-media-slot :target="'sections.'.$id.'.items'" kind="image" :append="$id" :label="__('manager_site.items.gallery.upload')" :can-manage="$canManage" :key="'gallery-'.$id.'-'.count($section['items'])" />
        @endif
        @if($kind === 'team' && ($options['employees'] ?? []) === [])
            <x-ui.notice tone="info" :message="__('manager_site.items.team.none')" />
        @endif

        @if($section['items'] === [])
            <x-ui.empty-state compact :icon="$kind === 'gallery' ? 'image' : 'layers'" :title="__('manager_site.items.'.$kind.'.empty')" />
        @else
            <ul @class(['cms-item-list', 'sb-list', 'sb-gallery-list' => $kind === 'gallery']) @if($canManage) x-data x-sortable="sortItems" data-sortable-group="items-{{ $id }}" @endif>
                @foreach($section['items'] as $index => $item)
                    <li class="cms-item" data-sortable-item="sections.{{ $id }}.items|{{ $item['id'] }}" wire:key="item-{{ $id }}-{{ $item['id'] }}" x-data="{ open: {{ ($kind === 'team' ? ($item['employee_uuid'] ?? '') === '' : ($kind !== 'gallery' && ($item['title'][$primary] ?? '') === '')) ? 'true' : 'false' }} }" @if(! ($item['enabled'] ?? true)) data-disabled="true" @endif>
                        <div class="cms-item__head">
                            @if($canManage)
                                <button type="button" class="icon-button icon-button--sm sb-grip" data-sortable-handle aria-label="{{ __('manager_site.actions.drag', ['name' => $index + 1]) }}"><x-ui.icon name="grip" /></button>
                            @endif
                            <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'">
                                <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
                                @if($kind === 'feature')<span class="cms-item__icon" aria-hidden="true"><x-ui.icon :name="$item['icon'] ?: 'sparkles'" size="16" /></span>@endif
                                @if($kind === 'gallery' && isset($media[$item['image']]))<img class="sb-thumb" src="{{ $media[$item['image']]['url'] }}" alt="">@endif
                                <span class="cms-item__title">
                                    @if($kind === 'team')
                                        {{ $names['employees'][$item['employee_uuid']] ?? ($item['employee_uuid'] !== '' ? __('manager_site.items.team.unavailable') : __('manager_site.items.team.choose')) }}
                                    @else
                                        {{ (($item['title'][app()->getLocale()] ?? '') ?: ($item['title'][$primary] ?? '')) ?: __('manager_site.untitled_item', ['number' => $index + 1]) }}
                                    @endif
                                </span>
                            </button>
                            <div class="cms-item__tools">
                                @if($canManage)
                                    <button class="icon-button icon-button--sm" type="button" wire:click="moveItem('sections.{{ $id }}.items', '{{ $item['id'] }}', -1)" @disabled($loop->first) aria-label="{{ __('manager_site.actions.move_up') }}" title="{{ __('manager_site.actions.move_up') }}"><x-ui.icon name="arrow-up" /></button>
                                    <button class="icon-button icon-button--sm" type="button" wire:click="moveItem('sections.{{ $id }}.items', '{{ $item['id'] }}', 1)" @disabled($loop->last) aria-label="{{ __('manager_site.actions.move_down') }}" title="{{ __('manager_site.actions.move_down') }}"><x-ui.icon name="arrow-down" /></button>
                                    @if($kind !== 'gallery')
                                        <button class="icon-button icon-button--sm" type="button" wire:click="duplicateItem('sections.{{ $id }}.items', '{{ $item['id'] }}')" @disabled(count($section['items']) >= $max) aria-label="{{ __('manager_site.actions.duplicate') }}" title="{{ __('manager_site.actions.duplicate') }}"><x-ui.icon name="copy" /></button>
                                    @endif
                                @endif
                                <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model.live="content.sections.{{ $id }}.items.{{ $index }}.enabled" aria-label="{{ __('manager_site.fields.enabled') }}"></label>
                                @if($canManage)
                                    <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeItem('sections.{{ $id }}.items', '{{ $item['id'] }}')" wire:confirm="{{ __('manager_site.confirm.remove_item') }}" data-confirm-title="{{ __('manager_site.confirm.remove_title') }}" data-confirm-tone="danger" aria-label="{{ __('manager_site.actions.remove') }}" title="{{ __('manager_site.actions.remove') }}"><x-ui.icon name="trash" /></button>
                                @endif
                            </div>
                        </div>
                        @include('livewire.center.appearance.site.partials.item-body', ['itemPath' => 'content.sections.'.$id.'.items.'.$index, 'target' => 'sections.'.$id.'.items.'.$index])
                    </li>
                @endforeach
            </ul>
        @endif
        @error('content.sections.'.$id.'.items')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
    </div>
</x-ui.card>
