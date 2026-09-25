{{--
    A multi-select of the center's own records, stored by uuid. A stored
    reference that is no longer publicly available stays listed (checked) so
    it can be removed; it is never shown on the live page.
    Params: $pickerPath (Livewire path to the uuid list), $rows (list of {uuid, name, category?}), $selected (list), $names (uuid => name), $domId, $legend, $empty.
--}}
<fieldset class="sb-picker">
    <legend>{{ $legend }} <span class="muted">· {{ trans_choice('manager_site.picker.selected', count($selected), ['count' => count($selected)]) }}</span></legend>
    @if($rows === [] && $selected === [])
        <p class="field-help">{{ $empty }}</p>
    @else
        <div class="sb-picker__list" role="group">
            @foreach($selected as $uuid)
                @if(! isset($names[$uuid]))
                    <label class="choice sb-picker__row is-stale" wire:key="{{ $domId }}-stale-{{ $uuid }}">
                        <input type="checkbox" value="{{ $uuid }}" wire:model.live="{{ $pickerPath }}">
                        <span>{{ __('manager_site.picker.unavailable') }}</span>
                    </label>
                @endif
            @endforeach
            @foreach($rows as $row)
                <label class="choice sb-picker__row" wire:key="{{ $domId }}-{{ $row['uuid'] }}">
                    <input type="checkbox" value="{{ $row['uuid'] }}" wire:model.live="{{ $pickerPath }}">
                    <span>{{ $row['name'] }}@if(! empty($row['category']))<small class="muted"> · {{ $row['category'] }}</small>@endif</span>
                </label>
            @endforeach
        </div>
    @endif
    @error($pickerPath)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</fieldset>
