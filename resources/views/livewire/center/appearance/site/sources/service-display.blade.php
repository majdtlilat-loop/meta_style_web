{{-- What a service card shows. Params: $sourcePath. --}}
<div class="sb-switches">
    @foreach(['show_prices', 'show_duration', 'show_images'] as $flag)
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $sourcePath }}.{{ $flag }}"><span>{{ __('manager_site.sources.services.'.$flag) }}</span></label>
    @endforeach
</div>
