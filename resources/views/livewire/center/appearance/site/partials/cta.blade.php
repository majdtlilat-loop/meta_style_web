{{--
    One call to action: label per enabled language, destination (a section on
    this page, one of the center's pages, or an https address), style, new tab.
    Params: $path (Livewire path), $cta, $title, $domId.
--}}
<div class="cms-item" x-data="{ open: {{ ($cta['enabled'] ?? false) && ($cta['label'][$primary] ?? '') === '' ? 'true' : 'false' }} }" @if(! ($cta['enabled'] ?? false)) data-disabled="true" @endif>
    <div class="cms-item__head">
        <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $domId }}-body">
            <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
            <span class="cms-item__title">{{ $title }}</span>
            <span class="cms-item__summary">{{ ($cta['enabled'] ?? false) ? (($cta['label'][app()->getLocale()] ?? '') ?: ($cta['label'][$primary] ?? '')) : __('manager_site.hidden') }}</span>
        </button>
        <label class="switch-label" title="{{ __('manager_site.fields.enabled') }}">
            <input class="switch" type="checkbox" role="switch" wire:model.live="{{ $path }}.enabled" aria-label="{{ $title }}: {{ __('manager_site.fields.enabled') }}">
        </label>
    </div>
    <div class="cms-item__body stack stack--sm" id="{{ $domId }}-body" x-show="open" x-cloak>
        <x-ui.lang-tabs :id="$domId.'-label'" :locales="$locales" :primary="$primary" :values="[$path.'.label' => $cta['label'] ?? []]" :fields="[
            ['name' => $path.'.label', 'label' => __('manager_site.fields.label'), 'max' => $choices['limits']['label'], 'required' => (bool) ($cta['enabled'] ?? false)],
        ]" />
        <div class="cms-grid-3">
            <x-ui.field :label="__('manager_site.fields.link_type')" :for="$domId.'-type'" :name="$path.'.link_type'">
                <select id="{{ $domId }}-type" wire:model.live="{{ $path }}.link_type">
                    @foreach($choices['link_types'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            @include('livewire.center.appearance.site.partials.target', ['path' => $path, 'type' => $cta['link_type'] ?? 'page', 'value' => (string) ($cta['target'] ?? ''), 'domId' => $domId])
            <x-ui.field :label="__('manager_site.fields.style')" :for="$domId.'-style'" :name="$path.'.style'">
                <select id="{{ $domId }}-style" wire:model="{{ $path }}.style">
                    @foreach($choices['cta_styles'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        </div>
        @if(($cta['link_type'] ?? 'page') !== 'section')
            <label class="choice"><input type="checkbox" wire:model="{{ $path }}.new_tab"><span>{{ __('manager_site.fields.new_tab') }}</span></label>
        @endif
    </div>
</div>
