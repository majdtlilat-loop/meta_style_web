{{-- Opening hours from the branches' real schedules. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.hours.title')">
    @include('livewire.center.appearance.site.partials.picker', ['pickerPath' => $sourcePath.'.branch_uuids', 'rows' => $options['branches'] ?? [], 'selected' => $source['branch_uuids'], 'names' => $names['branches'], 'domId' => $id.'-branches', 'legend' => __('manager_site.sources.branches_pick'), 'empty' => __('manager_site.sources.no_branches')])
    <p class="field-help">{{ __('manager_site.sources.all_when_empty') }}</p>
</x-ui.card>
