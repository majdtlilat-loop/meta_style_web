@props([
    'name',
    'label',
    'type' => 'text',
    'help' => null,
    'required' => false,
    'id' => null,
])
@php
    $id ??= str_replace(['.', '[', ']'], '-', $name);
    $describedBy = trim(($help ? $id.'-help ' : '').($errors->has($name) ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}@if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        {{ $attributes->class(['input', 'input--invalid' => $errors->has($name)])->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false']) }}
        @if($required) required aria-required="true" @endif
        @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @if($help)<p class="field-help" id="{{ $id }}-help">{{ $help }}</p>@endif
    @error($name)<p class="error" id="{{ $id }}-error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</div>
