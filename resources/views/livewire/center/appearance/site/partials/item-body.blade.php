{{-- The editable body of one section item. Params: $itemPath (Livewire path), $target (content path), $item, $kind. --}}
<div class="cms-item__body stack stack--sm" x-show="open" x-cloak>
    @if($kind === 'team')
        <x-ui.field :label="__('manager_site.items.team.member')" :for="'item-'.$item['id'].'-employee'" :name="$itemPath.'.employee_uuid'" required>
            <select id="item-{{ $item['id'] }}-employee" wire:model.live="{{ $itemPath }}.employee_uuid">
                <option value="">{{ __('manager_site.fields.choose') }}</option>
                @foreach($options['employees'] ?? [] as $employee)<option value="{{ $employee['uuid'] }}">{{ $employee['name'] }}</option>@endforeach
            </select>
        </x-ui.field>
    @endif
    @if($kind !== 'gallery')
        <x-ui.lang-tabs :id="'item-'.$item['id']" :locales="$locales" :primary="$primary" :values="[$itemPath.'.title' => $item['title'] ?? [], $itemPath.'.body' => $item['body'] ?? []]" :fields="[
            ['name' => $itemPath.'.title', 'label' => __('manager_site.items.'.$kind.'.title_field'), 'max' => $choices['limits']['item_title'], 'required' => $kind === 'faq'],
            ['name' => $itemPath.'.body', 'label' => __('manager_site.items.'.$kind.'.body_field'), 'type' => 'textarea', 'rows' => 3, 'max' => $choices['limits']['item_body'], 'required' => $kind === 'faq', 'counter' => true],
        ]" />
    @else
        <x-ui.lang-tabs :id="'item-'.$item['id']" :locales="$locales" :primary="$primary" :values="[$itemPath.'.title' => $item['title'] ?? [], $itemPath.'.image_alt' => $item['image_alt'] ?? []]" :fields="[
            ['name' => $itemPath.'.title', 'label' => __('manager_site.items.gallery.caption'), 'max' => $choices['limits']['item_title']],
            ['name' => $itemPath.'.image_alt', 'label' => __('manager_site.fields.alt'), 'max' => $choices['limits']['alt']],
        ]" />
    @endif
    @if($kind === 'feature')
        <fieldset class="field">
            <legend>{{ __('manager_site.fields.icon') }}</legend>
            <div class="icon-picker" role="radiogroup">
                @foreach($choices['icons'] as $icon)
                    <label class="icon-picker__option" title="{{ __('manager_site.icons.'.$icon) }}">
                        <input class="sr-only" type="radio" name="item-{{ $item['id'] }}-icon" value="{{ $icon }}" wire:model.live="{{ $itemPath }}.icon">
                        <x-ui.icon :name="$icon" size="18" /><span class="sr-only">{{ __('manager_site.icons.'.$icon) }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif
    @if(in_array($kind, ['feature', 'team'], true))
        <div class="cms-grid-2">
            @include('livewire.center.appearance.site.partials.media', ['target' => $target.'.image', 'kind' => 'image', 'label' => $kind === 'team' ? __('manager_site.items.team.photo') : __('manager_site.fields.image'), 'uuid' => $item['image'] ?? '', 'poster' => ''])
            <x-ui.lang-tabs :id="'item-alt-'.$item['id']" :locales="$locales" :primary="$primary" :values="[$itemPath.'.image_alt' => $item['image_alt'] ?? []]" :fields="[
                ['name' => $itemPath.'.image_alt', 'label' => __('manager_site.fields.alt'), 'max' => $choices['limits']['alt']],
            ]" />
        </div>
    @endif
    @error($itemPath.'.image')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</div>
