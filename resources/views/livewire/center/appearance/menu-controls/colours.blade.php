{{--
    Brand inheritance plus the page's own two colours. Expects: $values,
    $disabled, $brandAvailable, $brandUrl (nullable), $idPrefix.
--}}
<div class="stack stack--sm">
    @include('livewire.center.appearance.menu-controls.switch', [
        'key' => 'inherit_brand',
        'label' => __('manager_appearance.colours.inherit'),
        'help' => $brandAvailable ? null : __('manager_appearance.colours.inherit_missing'),
        'disabled' => $disabled,
    ])
    @if (! $brandAvailable && $brandUrl)
        <p class="field-help"><a href="{{ $brandUrl }}" wire:navigate>{{ __('manager_appearance.colours.open_brand') }}</a></p>
    @endif
    <div class="form-grid">
        @foreach (['primary', 'accent'] as $colour)
            <div class="color-field">
                <label for="{{ $idPrefix }}-{{ $colour }}">{{ __('manager_appearance.colours.'.$colour) }}</label>
                <div class="color-field__control">
                    <input type="color" id="{{ $idPrefix }}-{{ $colour }}" wire:model.live.debounce.300ms="values.{{ $colour }}" @disabled($disabled)>
                    <input type="text" class="mono" dir="ltr" maxlength="7" wire:model.blur="values.{{ $colour }}" @disabled($disabled) aria-label="{{ __('manager_appearance.colours.'.$colour) }} (HEX)">
                </div>
                @error('values.'.$colour)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
    @if (($values['inherit_brand'] ?? false) && $brandAvailable)
        <p class="field-help">{{ __('manager_appearance.colours.fallback_note') }}</p>
    @endif
</div>
