{{-- A link destination for its type. Params: $path, $type, $value, $domId. --}}
<x-ui.field :label="__('manager_site.fields.target')" :for="$domId.'-target'" :name="$path.'.target'" :help="$type === 'external' ? __('manager_site.fields.https_help') : null">
    @if($type === 'section')
        <select id="{{ $domId }}-target" wire:model="{{ $path }}.target" dir="ltr">
            @if($value !== '' && ! in_array($value, $anchors, true))
                <option value="{{ $value }}">#{{ $value }} · {{ __('manager_site.fields.missing_anchor') }}</option>
            @endif
            @if($value === '')<option value="">{{ __('manager_site.fields.choose') }}</option>@endif
            @foreach($anchors as $anchor)<option value="{{ $anchor }}">#{{ $anchor }}</option>@endforeach
        </select>
    @elseif($type === 'page')
        <select id="{{ $domId }}-target" wire:model="{{ $path }}.target">
            @if(! array_key_exists($value, $choices['pages']))<option value="">{{ __('manager_site.fields.choose') }}</option>@endif
            @foreach($choices['pages'] as $page => $text)<option value="{{ $page }}">{{ $text }}</option>@endforeach
        </select>
    @else
        <input id="{{ $domId }}-target" type="url" dir="ltr" inputmode="url" wire:model.blur="{{ $path }}.target" placeholder="https://" maxlength="300" autocomplete="off">
    @endif
</x-ui.field>
