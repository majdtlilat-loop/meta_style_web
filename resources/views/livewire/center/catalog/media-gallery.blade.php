{{--
    Photos of one service (an ordered gallery, the first is the cover) or the
    single image of a category. Uploads are checked here for type, size and
    dimensions, and again on the bytes by the media kernel.
--}}
<div class="catalog-gallery">
    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

    @if($items !== [])
        <ul class="catalog-gallery__grid" role="list" @if($canManage && $multiple) x-data x-sortable="moveMedia" data-sortable-group="media-{{ $owner }}-{{ $ownerUuid }}" @endif>
            @foreach($items as $item)
                <li class="catalog-photo" data-sortable-item="{{ $item['uuid'] }}" wire:key="media-{{ $item['uuid'] }}">
                    @if($item['url'])
                        <img src="{{ $item['url'] }}" alt="" loading="lazy">
                    @endif
                    @if($multiple && $item['first'])
                        <span class="catalog-photo__badge"><x-ui.icon name="star" size="12" />{{ __('manager_catalog.media.cover') }}</span>
                    @endif
                    @if($canManage)
                        <div class="catalog-photo__tools">
                            @if($multiple)
                                <button type="button" class="icon-button icon-button--xs" data-sortable-handle aria-label="{{ __('manager_catalog.media.drag', ['number' => $loop->iteration]) }}" title="{{ __('manager_catalog.ordering.drag_hint') }}"><x-ui.icon name="grip" size="14" /></button>
                                <button type="button" class="icon-button icon-button--xs" wire:click="moveMediaBy('{{ $item['uuid'] }}', -1)" @disabled($item['first']) aria-label="{{ __('manager_catalog.media.move_earlier', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.move_up') }}"><x-ui.icon name="arrow-up" size="14" /></button>
                                <button type="button" class="icon-button icon-button--xs" wire:click="moveMediaBy('{{ $item['uuid'] }}', 1)" @disabled($item['last']) aria-label="{{ __('manager_catalog.media.move_later', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.move_down') }}"><x-ui.icon name="arrow-down" size="14" /></button>
                                @unless($item['first'])
                                    <button type="button" class="icon-button icon-button--xs" wire:click="moveMedia('{{ $item['uuid'] }}', 0)" aria-label="{{ __('manager_catalog.media.make_cover', ['number' => $loop->iteration]) }}" title="{{ __('manager_catalog.media.make_cover_short') }}"><x-ui.icon name="star" size="14" /></button>
                                @endunless
                            @endif
                            <button type="button" class="icon-button icon-button--xs icon-button--danger" wire:click="removeMedia('{{ $item['uuid'] }}')"
                                wire:confirm="{{ __('manager_catalog.media.remove_confirm') }}" data-confirm-title="{{ __('manager_catalog.media.remove_title') }}" data-confirm-tone="danger"
                                aria-label="{{ __('manager_catalog.media.remove', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.remove') }}"><x-ui.icon name="trash" size="14" /></button>
                        </div>
                    @endif
                    @if($item['size'])
                        <span class="catalog-photo__size" dir="ltr">{{ $item['size'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if($canManage)
        @if($full)
            <p class="field-help">{{ __('manager_catalog.media.full', ['count' => $max]) }}</p>
        @else
            <label class="dropzone catalog-gallery__drop" for="media-upload-{{ $owner }}-{{ $ownerUuid }}">
                <x-ui.icon name="upload" />
                <strong>{{ $multiple ? __('manager_catalog.media.add_photos') : ($items === [] ? __('manager_catalog.media.add_image') : __('manager_catalog.media.replace_image')) }}</strong>
                <span class="field-help">{{ $multiple ? __('manager_catalog.media.rules_gallery', ['count' => $max]) : __('manager_catalog.media.rules_single') }}</span>
                <input id="media-upload-{{ $owner }}-{{ $ownerUuid }}" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" wire:model="photos" @if($multiple) multiple @endif>
            </label>
            <p class="field-help catalog-gallery__progress" wire:loading wire:target="photos"><span class="spinner" aria-hidden="true"></span>{{ __('manager_catalog.media.uploading') }}</p>
        @endif
    @elseif($items === [])
        <p class="muted">{{ __('manager_catalog.media.none') }}</p>
    @endif

    @error('photos')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
</div>
