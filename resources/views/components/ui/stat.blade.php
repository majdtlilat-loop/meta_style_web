@props([
    'label',
    'value',
    'hint' => null,
    'icon' => null,
    'tone' => null,
    'href' => null,
])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['stat', 'stat--link' => (bool) $href]) }} @if($tone) data-tone="{{ $tone }}" @endif>
    @if($icon)<span class="stat__icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span>@endif
    <p class="stat__label">{{ $label }}</p>
    <p class="stat__value">{{ $value }}</p>
    @if($hint)<p class="stat__hint">{{ $hint }}</p>@endif
    {{ $slot }}
</{{ $tag }}>
