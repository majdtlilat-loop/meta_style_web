@props([
    'value' => null,
    'label' => null,
    'tone' => null,
    'dot' => true,
])
{{--
    A lifecycle state as a human label with a consistent colour. Pass `label`
    already translated; `value` (the raw state) only chooses the tone and is
    never printed.
--}}
<span {{ $attributes->class([$dot ? 'status' : 'badge']) }} data-tone="{{ $tone ?? \App\View\StatusTone::for($value) }}">{{ $label ?? $slot }}</span>
