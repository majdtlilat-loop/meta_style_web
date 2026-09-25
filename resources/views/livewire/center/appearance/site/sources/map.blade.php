{{-- A branch map: a link, or an embed drawn only from the branch's coordinates. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.map.title')">
    <div class="stack">
        <div class="cms-grid-3">
            @include('livewire.center.appearance.site.sources.branch-select')
            <x-ui.field :label="__('manager_site.sources.map.mode')" :for="$id.'-map-mode'" :name="$sourcePath.'.mode'">
                <select id="{{ $id }}-map-mode" wire:model="{{ $sourcePath }}.mode">
                    @foreach($choices['map_modes'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.sources.map.zoom')" :for="$id.'-map-zoom'" :name="$sourcePath.'.zoom'">
                <select id="{{ $id }}-map-zoom" wire:model="{{ $sourcePath }}.zoom">
                    @foreach(range(10, 18) as $zoom)<option value="{{ $zoom }}">{{ $zoom }}</option>@endforeach
                </select>
            </x-ui.field>
        </div>
        <p class="field-help">{{ __('manager_site.sources.map.safety') }}</p>
    </div>
</x-ui.card>
