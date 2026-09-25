@props([
    'name',
    'label',
    'id' => null,
    'autocomplete' => 'current-password',
    'required' => false,
    'help' => null,
])
@php
    $id ??= str_replace(['.', '[', ']'], '-', $name);
    $invalid = $errors->has($name);
    $describedBy = trim(($help ? $id.'-help ' : '').($invalid ? $id.'-error' : ''));
@endphp
{{--
    Password with an integrated show / hide control.

    The toggle is type="button", so it can never submit the form; its state is
    exposed through aria-pressed and its label, and the behaviour lives in
    resources/js/platform/theme.js ([data-password-toggle]). Livewire re-renders
    return the field to hidden, which is the safe default.
--}}
<div {{ $attributes->only('class')->class(['field']) }}>
    @isset($labelRow)
        <div class="field__row">
            <label for="{{ $id }}">{{ $label }}@if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
            {{ $labelRow }}
        </div>
    @else
        <label for="{{ $id }}">{{ $label }}@if($required)<span class="required" aria-hidden="true">*</span>@endif</label>
    @endisset
    <div class="password-field">
        <input id="{{ $id }}" name="{{ $name }}" type="password" autocomplete="{{ $autocomplete }}"
            {{ $attributes->except('class')->merge(['aria-invalid' => $invalid ? 'true' : 'false']) }}
            @if($required) required aria-required="true" @endif
            @if($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
        <button type="button" class="password-field__toggle" data-password-toggle aria-controls="{{ $id }}" aria-pressed="false"
            aria-label="{{ __('ui.auth.show_password') }}" title="{{ __('ui.auth.show_password') }}"
            data-label-show="{{ __('ui.auth.show_password') }}" data-label-hide="{{ __('ui.auth.hide_password') }}">
            <span class="password-field__show"><x-ui.icon name="eye" /></span>
            <span class="password-field__hide"><x-ui.icon name="eye-off" /></span>
        </button>
    </div>
    @if($help)<p class="field-help" id="{{ $id }}-help">{{ $help }}</p>@endif
    @error($name)<p class="error" id="{{ $id }}-error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</div>
