{{-- Memberships or packages, read live. Params: $kind, $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.'.$kind.'.title')">
    <div class="stack">
        @unless($options['offers'][$kind] ?? false)
            <x-ui.notice tone="warning" :message="__('manager_site.sources.'.$kind.'.not_offered')" />
        @endunless
        <div class="cms-grid-2">
            <x-ui.field :label="__('manager_site.sources.selection')" :for="$id.'-mode'" :name="$sourcePath.'.mode'">
                <select id="{{ $id }}-mode" wire:model.live="{{ $sourcePath }}.mode">
                    @foreach($choices['selection_modes'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.show_prices"><span>{{ __('manager_site.sources.services.show_prices') }}</span></label>
        </div>
        @if($source['mode'] === 'selected')
            @include('livewire.center.appearance.site.partials.picker', ['pickerPath' => $sourcePath.'.uuids', 'rows' => $options[$kind] ?? [], 'selected' => $source['uuids'], 'names' => $names[$kind], 'domId' => $id.'-'.$kind, 'legend' => __('manager_site.sources.'.$kind.'.pick'), 'empty' => __('manager_site.sources.'.$kind.'.none')])
        @endif
    </div>
</x-ui.card>
