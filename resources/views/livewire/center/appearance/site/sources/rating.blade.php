{{-- The aggregate rating only. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.rating.title')">
    <div class="stack">
        <div class="sb-switches">
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.show_count"><span>{{ __('manager_site.sources.rating.show_count') }}</span></label>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.show_distribution"><span>{{ __('manager_site.sources.rating.show_distribution') }}</span></label>
        </div>
        <x-ui.notice tone="info" :message="__('manager_site.sources.rating.privacy', ['min' => $choices['rating_min']])" />
    </div>
</x-ui.card>
