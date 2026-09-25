@props([
    'title',
    'description' => null,
    'icon' => null,
    'tone' => null,
    'close' => 'closePanel',
    'submit' => null,
    'size' => null,
])
{{--
    A Livewire-driven dialog: the component renders it while its state says
    so, and `close` names the method that clears that state. With `submit`
    the dialog IS the form, so Enter submits and the footer buttons post it.
    Focus is trapped inside and restored by the browser when it goes.
--}}
@php
    $id = 'modal-'.substr(md5($title), 0, 8);
    $tag = $submit ? 'form' : 'div';
@endphp
<div class="modal-backdrop" wire:click.self="{{ $close }}" x-data x-on:keydown.escape.window="window.msIsTopLayer($el) && $el.querySelector('[data-dialog-close]')?.click()">
    <{{ $tag }} {{ $attributes->class(['modal', 'modal--'.$size => $size]) }}
        role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-description" @endif
        x-trap.noscroll="true"
        @if($submit) wire:submit="{{ $submit }}" novalidate @endif>
        <button type="button" hidden data-dialog-close wire:click="{{ $close }}"></button>
        <div class="modal__body">
            @if($icon)
                <span class="modal__icon" @if($tone) data-tone="{{ $tone }}" @endif aria-hidden="true"><x-ui.icon :name="$icon" /></span>
            @endif
            <div>
                <h2 id="{{ $id }}-title">{{ $title }}</h2>
                @if($description)<p id="{{ $id }}-description">{{ $description }}</p>@endif
            </div>
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="modal__footer">{{ $footer }}</div>
        @endisset
    </{{ $tag }}>
</div>
