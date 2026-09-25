@props([
    'label',
    'for' => null,
    'name' => null,
    'help' => null,
    'required' => false,
])
{{--
    Wrapper for any control: label, required marker, help text and the inline
    error for `name`. The control itself is the slot.
--}}
<div {{ $attributes->class(['field']) }}>
    <label @if($for) for="{{ $for }}" @endif>{{ $label }}@if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
    {{ $slot }}
    @if($help)<p class="field-help" @if($for) id="{{ $for }}-help" @endif>{{ $help }}</p>@endif
    @if($name)
        @error($name)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
    @endif
</div>
