@props([
    'title',
    'description' => null,
    'close' => 'closePanel',
    'submit' => null,
    'size' => null,
])
{{--
    A side panel for reading or editing one record without leaving the list.
    Livewire state decides whether it exists; `close` clears that state.
--}}
@php
    $id = 'drawer-'.substr(md5($title), 0, 8);
    $tag = $submit ? 'form' : 'div';
@endphp
<div class="drawer-backdrop" wire:click.self="{{ $close }}" x-data x-on:keydown.escape.window="window.msIsTopLayer($el) && $el.querySelector('[data-dialog-close]')?.click()">
    <{{ $tag }} {{ $attributes->class(['drawer', 'drawer--'.$size => $size]) }}
        role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title"
        x-trap.noscroll="true"
        @if($submit) wire:submit="{{ $submit }}" novalidate @endif>
        <button type="button" hidden data-dialog-close wire:click="{{ $close }}"></button>
        <header class="drawer__header">
            <div>
                <h2 id="{{ $id }}-title">{{ $title }}</h2>
                @if($description)<p>{{ $description }}</p>@endif
            </div>
            <button class="icon-button" type="button" wire:click="{{ $close }}" aria-label="{{ __('ui.actions.close') }}" title="{{ __('ui.actions.close') }}">
                <x-ui.icon name="close" />
            </button>
        </header>
        <div class="drawer__body">{{ $slot }}</div>
        @isset($footer)
            <footer class="drawer__footer">{{ $footer }}</footer>
        @endisset
    </{{ $tag }}>
</div>
