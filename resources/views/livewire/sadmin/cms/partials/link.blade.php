{{-- One footer link: label per language, a path or http(s) address, new tab, on/off. --}}
@php
    $domId = str_replace('.', '-', $path);
    $name = trim((string) ($link['label'][app()->getLocale()] ?? '')) ?: ($link['label']['en'] ?? '');
@endphp
<div class="cms-item" wire:key="link-{{ $key }}" x-data="{ open: {{ trim((string) ($link['label']['en'] ?? '')) === '' ? 'true' : 'false' }} }" @if(! ($link['enabled'] ?? true)) data-disabled="true" @endif>
    <div class="cms-item__head">
        <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'">
            <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
            <span class="cms-item__title">{{ $name !== '' ? $name : __('sadmin_cms.menu.untitled') }}</span>
            <span class="cms-item__summary" dir="ltr">{{ $link['url'] ?? '' }}</span>
        </button>
        <div class="cms-item__tools">
            <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model.live="{{ $path }}.enabled" aria-label="{{ __('sadmin_cms.fields.enabled') }}"></label>
            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="{{ $remove }}" aria-label="{{ __('sadmin_cms.actions.remove') }}" title="{{ __('sadmin_cms.actions.remove') }}"><x-ui.icon name="trash" /></button>
        </div>
    </div>
    <div class="cms-item__body stack stack--sm" x-show="open" x-cloak>
        <x-ui.lang-tabs :id="$domId" primary="en" :values="[$path.'.label' => $link['label'] ?? []]" :fields="[
            ['name' => $path.'.label', 'label' => __('sadmin_cms.fields.label'), 'max' => 80, 'required' => true],
        ]" />
        <div class="cms-grid-2">
            <div class="field">
                <label for="{{ $domId }}-url">{{ __('sadmin_cms.fields.url') }}</label>
                <input id="{{ $domId }}-url" dir="ltr" wire:model="{{ $path }}.url" placeholder="/register">
            </div>
            <label class="choice"><input type="checkbox" wire:model="{{ $path }}.new_tab"><span>{{ __('sadmin_cms.fields.new_tab') }}</span></label>
        </div>
    </div>
</div>
