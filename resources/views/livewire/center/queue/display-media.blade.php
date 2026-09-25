{{--
    One screen's promotional images and videos. docs/17-QUEUE.md §9.

    Uploads are checked on their bytes by the media kernel (JPEG, PNG, WebP;
    MP4, WebM). Videos always play muted on the screen, so the ticket voice is
    never competed with. Order by drag or the move buttons; pause an item
    without deleting it; a caption shows over the item in the screen's
    current language.
--}}
<div>
    <x-ui.drawer :title="__('manager_queue.media.title_for', ['name' => $screenName])" close="closeMedia" size="lg">
        <div class="stack">
            <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

            <div class="display-media__settings">
                <label class="check-row">
                    <input type="checkbox" class="switch" wire:model.live="enabled">
                    <span>{{ __('manager_queue.media.show') }}</span>
                </label>
                <x-ui.field :label="__('manager_queue.media.slide_seconds')" for="media-seconds" name="slideSeconds" class="display-media__seconds">
                    <input id="media-seconds" type="number" min="{{ $minSeconds }}" max="{{ $maxSeconds }}" step="1" inputmode="numeric" wire:model.blur="slideSeconds">
                </x-ui.field>
                <span class="display-media__count muted">{{ __('manager_queue.media.capacity', ['count' => count($items), 'max' => $max]) }}</span>
            </div>

            @if($items === [])
                <x-ui.empty-state icon="image" compact :title="__('manager_queue.media.none')" />
            @else
                <ol class="display-media__list" role="list" x-data x-sortable="moveItem" data-sortable-group="display-media">
                    @foreach($items as $item)
                        <li class="display-media__item" data-sortable-item="{{ $item['uuid'] }}" wire:key="display-media-{{ $item['uuid'] }}" @unless($item['enabled']) data-muted="true" @endunless>
                            <div class="display-media__row">
                                <button type="button" class="icon-button icon-button--sm" data-sortable-handle aria-label="{{ __('manager_queue.media.drag', ['number' => $loop->iteration]) }}" title="{{ __('manager_queue.media.drag_hint') }}"><x-ui.icon name="grip" size="16" /></button>

                                <a class="display-media__thumb" href="{{ $item['url'] }}" target="_blank" rel="noopener" title="{{ __('manager_queue.media.view') }}">
                                    @if($item['kind'] === 'video')
                                        <video src="{{ $item['url'] }}" muted playsinline preload="metadata" aria-hidden="true"></video>
                                        <span class="display-media__kind"><x-ui.icon name="play" size="12" /></span>
                                    @else
                                        <img src="{{ $item['url'] }}" alt="{{ $item['alt_text'] }}" loading="lazy">
                                    @endif
                                </a>

                                <div class="display-media__body">
                                    <p class="display-media__caption" dir="auto">{{ $item['caption_text'] }}@if($item['caption_text'] === '')<span class="muted">{{ __('manager_queue.media.no_caption') }}</span>@endif</p>
                                    <p class="cell-sub">
                                        <x-ui.icon :name="$item['kind'] === 'video' ? 'video' : 'image'" size="12" />
                                        {{ $item['kind'] === 'video' ? __('manager_queue.media.video') : __('manager_queue.media.image') }}@if($item['size']) · <span dir="ltr">{{ $item['size'] }}</span>@endif @if($item['dimensions']) · <span dir="ltr">{{ $item['dimensions'] }}</span>@endif
                                    </p>
                                </div>

                                <div class="display-media__actions">
                                    <label class="switch-label" title="{{ $item['enabled'] ? __('manager_queue.media.playing') : __('manager_queue.media.paused') }}">
                                        <input type="checkbox" class="switch" @checked($item['enabled']) wire:click="toggleItem('{{ $item['uuid'] }}')" aria-label="{{ __('manager_queue.media.play_item', ['number' => $loop->iteration]) }}">
                                    </label>
                                    <button type="button" class="icon-button icon-button--sm" wire:click="moveItemBy('{{ $item['uuid'] }}', -1)" @disabled($item['first']) aria-label="{{ __('manager_queue.media.move_earlier', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.move_up') }}"><x-ui.icon name="arrow-up" size="16" /></button>
                                    <button type="button" class="icon-button icon-button--sm" wire:click="moveItemBy('{{ $item['uuid'] }}', 1)" @disabled($item['last']) aria-label="{{ __('manager_queue.media.move_later', ['number' => $loop->iteration]) }}" title="{{ __('ui.actions.move_down') }}"><x-ui.icon name="arrow-down" size="16" /></button>
                                    <button type="button" class="icon-button icon-button--sm" wire:click="editItem('{{ $item['uuid'] }}')" aria-label="{{ __('manager_queue.media.edit_texts', ['number' => $loop->iteration]) }}" title="{{ __('manager_queue.media.texts') }}" @if($editing === $item['uuid']) aria-expanded="true" @endif><x-ui.icon name="edit" size="16" /></button>
                                    <button type="button" class="icon-button icon-button--sm icon-button--danger" wire:click="removeItem('{{ $item['uuid'] }}')"
                                        wire:confirm="{{ __('manager_queue.media.remove_confirm') }}" data-confirm-title="{{ __('manager_queue.media.remove') }}" data-confirm-tone="danger"
                                        aria-label="{{ __('manager_queue.media.remove_item', ['number' => $loop->iteration]) }}" title="{{ __('manager_queue.media.remove') }}"><x-ui.icon name="trash" size="16" /></button>
                                </div>
                            </div>

                            @if($editing === $item['uuid'])
                                <div class="display-media__editor stack stack--sm">
                                    <x-ui.lang-tabs id="display-media-{{ $item['uuid'] }}" :fields="$textFields[$item['kind']]"
                                        :values="['caption' => $caption, 'alt' => $alt]" :locales="$locales" :primary="$primary" />
                                    <div class="cluster display-media__editor-actions">
                                        <x-ui.button variant="ghost" size="sm" wire:click="cancelItem">{{ __('ui.actions.cancel') }}</x-ui.button>
                                        <x-ui.button size="sm" wire:click="saveItem" wire:loading.attr="data-loading" wire:target="saveItem">{{ __('ui.actions.save') }}</x-ui.button>
                                    </div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            @if($full)
                <p class="field-help">{{ __('manager_queue.media.full', ['max' => $max]) }}</p>
            @else
                <label class="dropzone display-media__drop" for="display-media-files">
                    <x-ui.icon name="upload" />
                    <strong>{{ __('manager_queue.media.add') }}</strong>
                    <span class="field-help">{{ __('manager_queue.media.rules', ['image' => $imageMb, 'video' => $videoMb, 'max' => $max]) }}</span>
                    <input id="display-media-files" class="sr-only" type="file" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" wire:model="files">
                </label>
                <p class="field-help" wire:loading wire:target="files"><span class="spinner" aria-hidden="true"></span>{{ __('manager_queue.media.uploading') }}</p>
            @endif

            @error('files')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
        </div>

        <x-slot:footer>
            <span class="drawer__spacer"></span>
            <x-ui.button variant="ghost" wire:click="closeMedia">{{ __('ui.actions.close') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>
</div>
