<div class="stack resources-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.resources')" />

    <div class="segmented segmented--scroll resources-page__tabs" role="tablist" aria-label="{{ __('ui.manager_nav.items.resources') }}">
        @foreach($tabs as $section)
            <button type="button" role="tab" id="resources-tab-{{ $section }}" aria-selected="{{ $tab === $section ? 'true' : 'false' }}" aria-pressed="{{ $tab === $section ? 'true' : 'false' }}" wire:click="setTab('{{ $section }}')">{{ __('manager_staff.resources.tabs.'.$section) }}</button>
        @endforeach
    </div>

    <div role="tabpanel" aria-labelledby="resources-tab-{{ $tab }}">
        @if($tab === 'types')
            <livewire:center.resources.resource-types :key="'resources-types'" />
        @elseif($tab === 'requirements')
            <livewire:center.resources.service-requirements :key="'resources-requirements'" />
        @elseif($tab === 'blocks')
            <x-ui.card>
                <livewire:center.resources.availability-blocks :key="'resources-blocks'" />
            </x-ui.card>
        @else
            <livewire:center.resources.resource-list :key="'resources-list'" />
        @endif
    </div>
</div>
