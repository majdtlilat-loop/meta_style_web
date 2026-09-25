{{-- One branch's published contact details. Params: $source, $sourcePath. --}}
<x-ui.card :title="__('manager_site.sources.contact.title')">
    <div class="stack">
        @include('livewire.center.appearance.site.sources.branch-select')
        <div class="sb-switches">
            @foreach(['show_phone', 'show_whatsapp', 'show_email', 'show_address', 'show_map_link'] as $flag)
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.{{ $flag }}"><span>{{ __('manager_site.sources.contact.'.$flag) }}</span></label>
            @endforeach
        </div>
    </div>
</x-ui.card>
