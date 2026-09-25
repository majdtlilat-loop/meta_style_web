{{-- Catalog categories. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.categories.title')">
    <div class="stack">
        @include('livewire.center.appearance.site.partials.picker', ['pickerPath' => $sourcePath.'.category_uuids', 'rows' => $options['categories'] ?? [], 'selected' => $source['category_uuids'], 'names' => $names['categories'], 'domId' => $id.'-cats', 'legend' => __('manager_site.sources.categories.pick'), 'empty' => __('manager_site.sources.no_categories')])
        <div class="cms-grid-2">
            <x-ui.field :label="__('manager_site.sources.services.limit')" :for="$id.'-limit'" :name="$sourcePath.'.limit'">
                <select id="{{ $id }}-limit" wire:model="{{ $sourcePath }}.limit">
                    @foreach([4, 6, 8, 12, 16, 24] as $limit)<option value="{{ $limit }}">{{ $limit }}</option>@endforeach
                </select>
            </x-ui.field>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.show_images"><span>{{ __('manager_site.sources.services.show_images') }}</span></label>
        </div>
    </div>
</x-ui.card>
