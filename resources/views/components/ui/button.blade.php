@props([
    'type' => 'button',
    'variant' => 'primary',
    'size' => null,
    'icon' => null,
    'iconAfter' => null,
    'href' => null,
    'block' => false,
])
@php
    $classes = [
        'button',
        'button--secondary' => $variant === 'secondary',
        'button--ghost' => $variant === 'ghost',
        'button--subtle' => $variant === 'subtle',
        'button--danger' => $variant === 'danger',
        'button--danger-soft' => $variant === 'danger-soft',
        'button--sm' => $size === 'sm',
        'button--lg' => $size === 'lg',
        'button--block' => $block,
        'button--icon' => $icon !== null && trim((string) $slot) === '',
    ];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if($icon)<x-ui.icon :name="$icon" />@endif{{ $slot }}@if($iconAfter)<x-ui.icon :name="$iconAfter" />@endif</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>@if($icon)<x-ui.icon :name="$icon" />@endif{{ $slot }}@if($iconAfter)<x-ui.icon :name="$iconAfter" />@endif</button>
@endif
