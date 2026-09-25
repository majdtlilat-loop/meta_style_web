@props(['key' => 'notice', 'tone' => 'success'])
{{-- The outcome of the last action, dismissible, announced politely. --}}
@if(session($key))
    <div {{ $attributes->class(['notice', 'notice--dismissible']) }} data-tone="{{ $tone }}" role="status" x-data="{ shown: true }" x-show="shown">
        <x-ui.icon :name="$tone === 'danger' ? 'alert-circle' : ($tone === 'warning' ? 'alert-triangle' : 'check-circle')" />
        <p>{{ session($key) }}</p>
        <button class="icon-button icon-button--sm" type="button" x-on:click="shown = false" aria-label="{{ __('ui.actions.close') }}"><x-ui.icon name="close" size="16" /></button>
    </div>
@endif
