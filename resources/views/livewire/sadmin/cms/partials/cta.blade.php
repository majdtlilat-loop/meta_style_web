{{-- One call-to-action button: label per language, destination, style. --}}
@php
    $id = str_replace('.', '-', $path);
@endphp
<div class="cms-item" x-data="{ open: {{ ($cta['enabled'] ?? false) && trim((string) ($cta['label']['en'] ?? '')) === '' ? 'true' : 'false' }} }" @if(! ($cta['enabled'] ?? false)) data-disabled="true" @endif>
    <div class="cms-item__head">
        <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $id }}-body">
            <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
            <span class="cms-item__title">{{ $title }}</span>
            <span class="cms-item__summary">{{ ($cta['enabled'] ?? false) ? (trim((string) ($cta['label'][app()->getLocale()] ?? '')) ?: ($cta['label']['en'] ?? '')) : __('sadmin_cms.hidden') }}</span>
        </button>
        <label class="switch-label" title="{{ __('sadmin_cms.fields.enabled') }}">
            <input class="switch" type="checkbox" role="switch" wire:model.live="{{ $path }}.enabled" aria-label="{{ $title }}: {{ __('sadmin_cms.fields.enabled') }}">
        </label>
    </div>
    <div class="cms-item__body stack stack--sm" id="{{ $id }}-body" x-show="open" x-cloak>
        <x-ui.lang-tabs :id="$id" primary="en" :values="[$path.'.label' => $cta['label'] ?? []]" :fields="[
            ['name' => $path.'.label', 'label' => __('sadmin_cms.fields.label'), 'max' => 80, 'required' => true],
        ]" />
        <div class="cms-grid-2">
            <div class="field">
                <label for="{{ $id }}-url">{{ __('sadmin_cms.fields.url') }}</label>
                <input id="{{ $id }}-url" dir="ltr" wire:model="{{ $path }}.url" placeholder="/register">
            </div>
            <div class="field">
                <label for="{{ $id }}-style">{{ __('sadmin_cms.fields.style') }}</label>
                <select id="{{ $id }}-style" wire:model="{{ $path }}.style">
                    @foreach(['primary', 'secondary', 'link'] as $style)
                        <option value="{{ $style }}">{{ __('sadmin_cms.options.'.$style) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label class="choice"><input type="checkbox" wire:model="{{ $path }}.new_tab"><span>{{ __('sadmin_cms.fields.new_tab') }}</span></label>
    </div>
</div>
