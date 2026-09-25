{{-- Branch cards from real, public branches. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.branches.title')">
    <div class="stack">
        @include('livewire.center.appearance.site.partials.picker', ['pickerPath' => $sourcePath.'.branch_uuids', 'rows' => $options['branches'] ?? [], 'selected' => $source['branch_uuids'], 'names' => $names['branches'], 'domId' => $id.'-branches', 'legend' => __('manager_site.sources.branches_pick'), 'empty' => __('manager_site.sources.no_branches')])
        <p class="field-help">{{ __('manager_site.sources.all_when_empty') }}</p>
        <div class="sb-switches">
            @foreach(['show_hours', 'show_contact', 'show_map_link'] as $flag)
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.{{ $flag }}"><span>{{ __('manager_site.sources.branches.'.$flag) }}</span></label>
            @endforeach
        </div>
    </div>
</x-ui.card>
