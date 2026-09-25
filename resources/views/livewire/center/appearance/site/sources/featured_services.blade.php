{{-- Hand-picked services, read live from the catalog. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.featured.title')">
    <div class="stack">
        @include('livewire.center.appearance.site.partials.picker', ['pickerPath' => $sourcePath.'.service_uuids', 'rows' => $options['services'] ?? [], 'selected' => $source['service_uuids'], 'names' => $names['services'], 'domId' => $id.'-services', 'legend' => __('manager_site.sources.featured.services', ['max' => $choices['max']['selected']]), 'empty' => __('manager_site.sources.no_services')])
        <x-ui.field :label="__('manager_site.sources.services.link')" :for="$id.'-link'" :name="$sourcePath.'.link'">
            <select id="{{ $id }}-link" wire:model="{{ $sourcePath }}.link">
                @foreach($choices['service_links'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
            </select>
        </x-ui.field>
        @include('livewire.center.appearance.site.sources.service-display')
    </div>
</x-ui.card>
