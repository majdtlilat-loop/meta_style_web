{{--
    One closed choice as a segmented control (radio group), used by the booking,
    cart and print appearance editors. Expects: $key (values.<key>), $label,
    $options (value => translated label), $current, $disabled, optional $help.
--}}
<div class="field" wire:key="choice-{{ $key }}">
    <span class="field-label" id="choice-{{ $key }}">{{ $label }}</span>
    <div class="segmented segmented--scroll" role="radiogroup" aria-labelledby="choice-{{ $key }}">
        @foreach ($options as $value => $optionLabel)
            <label @class(['is-active' => $current === (string) $value])>
                <input class="sr-only" type="radio" name="choice-{{ $key }}" value="{{ $value }}" wire:model.live="values.{{ $key }}" @disabled($disabled)>{{ $optionLabel }}
            </label>
        @endforeach
    </div>
    @if (! empty($help))<p class="field-help">{{ $help }}</p>@endif
    @error('values.'.$key)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</div>
