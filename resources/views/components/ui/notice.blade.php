@props(['message' => '', 'tone' => 'success', 'dismiss' => null])
{{-- A component's own outcome message (the Livewire `$notice` pattern). --}}
@if(trim((string) $message) !== '')
    <div {{ $attributes->class(['notice', 'notice--dismissible']) }} data-tone="{{ $tone }}" role="status">
        <x-ui.icon :name="$tone === 'danger' ? 'alert-circle' : ($tone === 'warning' ? 'alert-triangle' : ($tone === 'info' ? 'info' : 'check-circle'))" />
        <p>{{ $message }}</p>
        @if($dismiss)
            <button class="icon-button icon-button--sm" type="button" wire:click="{{ $dismiss }}" aria-label="{{ __('ui.actions.close') }}"><x-ui.icon name="close" size="16" /></button>
        @endif
    </div>
@endif
