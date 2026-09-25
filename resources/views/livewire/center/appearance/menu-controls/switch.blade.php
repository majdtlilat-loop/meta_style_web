{{--
    One on/off setting as a labelled switch row. Expects: $key (values.<key>),
    $label, $disabled, optional $help.
--}}
<div class="setting-row appearance-switch" wire:key="switch-{{ $key }}">
    <div>
        <label for="switch-{{ $key }}"><strong>{{ $label }}</strong></label>
        @if (! empty($help))<p>{{ $help }}</p>@endif
    </div>
    <input id="switch-{{ $key }}" type="checkbox" class="switch" role="switch" wire:model.live="values.{{ $key }}" @disabled($disabled)>
</div>
