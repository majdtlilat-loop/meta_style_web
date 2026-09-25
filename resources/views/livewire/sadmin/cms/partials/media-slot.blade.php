{{--
    One approved media slot. Choosing a file uploads it straight into this
    slot through the component's validated uploadMedia(); removing only
    unreferences it from the draft — published revisions may still use it.
--}}
@php
    $slotId = 'media-'.str_replace('.', '-', $target);
    $url = $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
@endphp
<article class="cms-media-card" x-data="{ uploading: false }">
    <div class="cms-media-card__preview">
        @if($url && $kind === 'video')
            <video controls preload="metadata" muted @if($poster) poster="{{ $poster }}" @endif><source src="{{ $url }}"></video>
        @elseif($url)
            <img src="{{ $url }}" alt="" loading="lazy">
        @else
            <x-ui.icon :name="$kind === 'video' ? 'video' : 'image'" size="28" />
        @endif
        <div class="cms-media-card__progress" x-show="uploading" x-cloak><span class="spinner" aria-hidden="true"></span>{{ __('sadmin_cms.media_ui.uploading') }}</div>
    </div>
    <div class="cms-media-card__body">
        <strong>{{ $label }}</strong>
        <span class="field-help">{{ $kind === 'video' ? __('sadmin_cms.media_ui.video_rules') : __('sadmin_cms.media_ui.image_rules') }}</span>
    </div>
    <div class="cms-media-card__actions">
        <label class="button button--secondary button--sm" for="{{ $slotId }}">
            <x-ui.icon name="upload" size="16" />{{ $path ? __('sadmin_cms.media_ui.replace') : __('sadmin_cms.media_ui.upload') }}
        </label>
        <input class="sr-only" id="{{ $slotId }}" type="file" accept="{{ $kind === 'video' ? 'video/mp4,video/webm' : 'image/jpeg,image/png,image/webp' }}"
            x-on:change="if ($event.target.files.length) { uploading = true; $wire.set('mediaTarget', @js($target), false); $wire.upload('mediaUpload', $event.target.files[0], () => { $wire.uploadMedia().then(() => { uploading = false; $event.target.value = '' }) }, () => { uploading = false }) }">
        @if($path)
            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="removeMedia('{{ $target }}')" wire:confirm="{{ __('sadmin_cms.remove_confirm') }}">{{ __('sadmin_cms.actions.remove_media') }}</button>
        @endif
    </div>
</article>
