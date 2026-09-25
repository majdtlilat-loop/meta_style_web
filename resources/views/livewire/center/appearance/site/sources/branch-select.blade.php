{{-- One branch (empty = the main public branch). Params: $source, $sourcePath. --}}
<x-ui.field :label="__('manager_site.sources.branch')" :for="$id.'-branch'" :name="$sourcePath.'.branch_uuid'">
    <select id="{{ $id }}-branch" wire:model="{{ $sourcePath }}.branch_uuid">
        <option value="">{{ __('manager_site.sources.main_branch') }}</option>
        @if($source['branch_uuid'] !== '' && ! isset($names['branches'][$source['branch_uuid']]))
            <option value="{{ $source['branch_uuid'] }}">{{ __('manager_site.picker.unavailable') }}</option>
        @endif
        @foreach($options['branches'] ?? [] as $branch)<option value="{{ $branch['uuid'] }}">{{ $branch['name'] }}</option>@endforeach
    </select>
</x-ui.field>
