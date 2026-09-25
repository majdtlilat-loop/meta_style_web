<article class="cms-media-card sb-media" x-data="{ uploading: false }"
    x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false">
    <div class="cms-media-card__preview">
        @if($url && $kind === 'video')
            <video controls preload="metadata" muted playsinline @if($poster) poster="{{ $poster }}" @endif><source src="{{ $url }}"></video>
        @elseif($url)
            <img src="{{ $url }}" alt="" loading="lazy">
        @else
            <x-ui.icon :name="$kind === 'video' ? 'video' : 'image'" size="28" />
        @endif
        <div class="cms-media-card__progress" x-show="uploading" x-cloak><span class="spinner" aria-hidden="true"></span>{{ __('manager_site.media.uploading') }}</div>
    </div>
    <div class="cms-media-card__body">
        <strong>{{ $label }}</strong>
        <span class="field-help">{{ $rules }}</span>
    </div>
    @if($canManage)
        <div class="cms-media-card__actions">
            @if($append !== '')
                <label class="button button--secondary button--sm" for="{{ $inputId }}"><x-ui.icon name="upload" size="16" />{{ __('manager_site.media.add_images') }}</label>
                <input class="sr-only" id="{{ $inputId }}" type="file" multiple accept="{{ $accept }}" wire:model="uploads">
            @else
                <label class="button button--secondary button--sm" for="{{ $inputId }}"><x-ui.icon name="upload" size="16" />{{ $url ? __('manager_site.media.replace') : __('manager_site.media.upload') }}</label>
                <input class="sr-only" id="{{ $inputId }}" type="file" accept="{{ $accept }}" wire:model="file">
                @if($url)
                    <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="$parent.clearMedia('{{ $target }}')" wire:confirm="{{ __('manager_site.confirm.remove_media') }}" data-confirm-title="{{ __('manager_site.confirm.remove_media_title') }}" data-confirm-tone="danger">{{ __('manager_site.media.remove') }}</button>
                @endif
            @endif
        </div>
    @endif
    @error('file')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
    @error('uploads.*')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</article>
