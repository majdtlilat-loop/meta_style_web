{{-- Services, read live from the catalog. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.services.title')">
    <div class="stack">
        <div class="cms-grid-3">
            <x-ui.field :label="__('manager_site.sources.services.mode')" :for="$id.'-mode'" :name="$sourcePath.'.mode'">
                <select id="{{ $id }}-mode" wire:model.live="{{ $sourcePath }}.mode">
                    @foreach($choices['service_modes'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.sources.services.limit')" :for="$id.'-limit'" :name="$sourcePath.'.limit'">
                <select id="{{ $id }}-limit" wire:model="{{ $sourcePath }}.limit">
                    @foreach([3, 4, 6, 8, 9, 12, 16, 24] as $limit)<option value="{{ $limit }}">{{ $limit }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.sources.services.link')" :for="$id.'-link'" :name="$sourcePath.'.link'">
                <select id="{{ $id }}-link" wire:model="{{ $sourcePath }}.link">
                    @foreach($choices['service_links'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        </div>
        @if($source['mode'] === 'categories')
            @include('livewire.center.appearance.site.partials.picker', ['pickerPath' => $sourcePath.'.category_uuids', 'rows' => $options['categories'] ?? [], 'selected' => $source['category_uuids'], 'names' => $names['categories'], 'domId' => $id.'-cats', 'legend' => __('manager_site.sources.services.categories'), 'empty' => __('manager_site.sources.no_categories')])
        @endif
        @include('livewire.center.appearance.site.sources.service-display')
    </div>
</x-ui.card>
