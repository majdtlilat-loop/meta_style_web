{{--
    Waiting-room screens. docs/17-QUEUE.md §§9, 16.

    The address below carries the screen's opaque key: open it on the
    television. A leaked link is fixed by rotating it — the old address then
    answers 404. Screens are `queue_display`, voice is `queue_voice`; without
    them the controls say so rather than pretending.

    A screen may rotate its labels through the center's languages and play
    promotional media (DisplayMedia, its own panel). Preview opens the real
    screen page in a frame, for this manager only (docs/17-QUEUE.md §9).
--}}
<div>
    <x-ui.card class="queue-screens-card" :title="__('manager_queue.setup.displays_title')" flush>
        <x-slot:actions>
            @if($entitled)
                <x-ui.button size="sm" icon="plus" wire:click="create">{{ __('manager_queue.setup.add_display') }}</x-ui.button>
            @endif
            <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_queue.setup.display_help') }}" aria-label="{{ __('manager_queue.setup.display_help') }}"><x-ui.icon name="info" size="16" /></span>
        </x-slot:actions>

        @if(! $screens || ($notice !== '' && $editing === ''))
            <div class="card__body stack stack--sm">
                @if(! $screens)
                    <x-ui.notice :message="__('manager_queue.setup.screens_locked')" tone="warning" />
                @endif
                @if($editing === '')
                    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />
                @endif
            </div>
        @endif

        @if($displays === [])
            <x-ui.empty-state icon="monitor" compact :title="__('manager_queue.setup.no_displays')">
                @if($entitled)
                    <x-ui.button size="sm" icon="plus" wire:click="create">{{ __('manager_queue.setup.add_display') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        @else
            <ul class="queue-displays">
                @foreach($displays as $display)
                    <li class="queue-display" wire:key="display-{{ $display['uuid'] }}" @if(! $display['is_active']) data-muted="true" @endif>
                        <span class="queue-display__icon" aria-hidden="true"><x-ui.icon name="monitor" /></span>
                        <div class="queue-display__body">
                            <p class="queue-display__name">
                                <strong>{{ $display['name'] }}</strong>
                                <x-ui.status :value="$display['is_active'] ? 'active' : 'inactive'" :label="$display['is_active'] ? __('ui.states.active') : __('ui.states.inactive')" />
                            </p>
                            <p class="cell-sub">
                                {{ $display['branch'] }} ·
                                @if($display['scope'] === 'service_point'){{ __('manager_queue.setup.scope_desk', ['code' => $display['service_point']]) }}
                                @elseif($display['scope'] === 'department'){{ $display['department'] }}
                                @else{{ __('manager_queue.setup.scope_branch') }}@endif
                            </p>
                            <p class="chip-list">
                                <span class="chip">{{ $display['locale_label'] ?? __('manager_queue.setup.language_auto') }}</span>
                                <span class="chip">{{ __('manager_queue.setup.recent_count', ['count' => $display['recent']]) }}</span>
                                <span class="chip @unless($display['sound']) chip--muted @endunless"><x-ui.icon :name="$display['sound'] ? 'volume' : 'volume-off'" size="12" /> {{ __('manager_queue.setup.sound') }}</span>
                                @if($voiceEntitled)
                                    <span class="chip @unless($display['voice']) chip--muted @endunless"><x-ui.icon name="megaphone" size="12" /> {{ __('manager_queue.setup.voice') }}</span>
                                @endif
                                @if($display['rotation_labels'] !== [])
                                    <span class="chip" title="{{ __('manager_queue.setup.rotate_label') }}"><x-ui.icon name="languages" size="12" /> {{ implode(' · ', $display['rotation_labels']) }} · {{ __('manager_queue.setup.seconds', ['count' => $display['rotation_seconds']]) }}</span>
                                @endif
                                @if($screens && $display['media_count'] > 0)
                                    <span class="chip @unless($display['promo']) chip--muted @endunless" title="{{ __('manager_queue.media.title') }}"><x-ui.icon name="image" size="12" /> {{ __('manager_queue.media.count', ['playing' => $display['media_playing'], 'count' => $display['media_count']]) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="queue-display__actions">
                            @if($display['preview'])
                                <button type="button" class="icon-button icon-button--sm icon-button--bordered" x-data x-on:click="$dispatch('queue-display-preview', @js($display['preview']))" title="{{ __('manager_queue.preview.open') }}" aria-label="{{ __('manager_queue.preview.open_for', ['name' => $display['name']]) }}"><x-ui.icon name="eye" size="16" /></button>
                            @endif
                            @if($entitled && $screens)
                                <button type="button" class="icon-button icon-button--sm icon-button--bordered" wire:click="openMedia('{{ $display['uuid'] }}')" title="{{ __('manager_queue.media.title') }}" aria-label="{{ __('manager_queue.media.open_for', ['name' => $display['name']]) }}"><x-ui.icon name="image" size="16" /></button>
                            @endif
                            @if($display['url'])
                                <a class="button button--secondary button--sm" href="{{ $display['url'] }}" target="_blank" rel="noopener"><x-ui.icon name="external" size="16" />{{ __('manager_queue.setup.open_screen') }}</a>
                                <button type="button" class="icon-button icon-button--sm icon-button--bordered" x-data x-on:click="navigator.clipboard?.writeText(@js($display['url'])); $el.dataset.copied = 'true'; setTimeout(() => delete $el.dataset.copied, 1500)" title="{{ __('ui.actions.copy_link') }}" aria-label="{{ __('ui.actions.copy_link') }}"><x-ui.icon name="copy" size="16" /></button>
                            @endif
                            @if($entitled)
                                <button type="button" class="icon-button icon-button--sm icon-button--bordered" wire:click="edit('{{ $display['uuid'] }}')" title="{{ __('ui.actions.edit') }}" aria-label="{{ __('ui.actions.edit') }}"><x-ui.icon name="edit" size="16" /></button>
                                <button type="button" class="icon-button icon-button--sm icon-button--bordered" wire:click="rotate('{{ $display['uuid'] }}')"
                                    wire:confirm="{{ __('manager_queue.setup.rotate_confirm', ['name' => $display['name']]) }}"
                                    data-confirm-title="{{ __('manager_queue.setup.rotate') }}" data-confirm-tone="danger"
                                    title="{{ __('manager_queue.setup.rotate') }}" aria-label="{{ __('manager_queue.setup.rotate') }}"><x-ui.icon name="refresh" size="16" /></button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if($editing !== '')
        <x-ui.drawer :title="$editing === 'new' ? __('manager_queue.setup.add_display') : __('manager_queue.setup.edit_display')" close="closePanel" submit="save">
            <div class="stack">
                <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="dismissNotice" />

                <div class="form-grid">
                    <x-ui.field :label="__('manager_queue.setup.display_name')" for="display-name" name="name" required class="form-grid__full">
                        <input id="display-name" type="text" wire:model="name" maxlength="190" autocomplete="off" placeholder="{{ __('manager_queue.setup.display_name_placeholder') }}">
                    </x-ui.field>
                    <x-ui.field :label="__('ui.fields.branch')" for="display-branch" required>
                        <select id="display-branch" wire:model.live="branch" @disabled($editing !== 'new')>
                            @foreach($branches as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field :label="__('manager_queue.setup.language')" for="display-locale" name="locale">
                        <select id="display-locale" wire:model="locale">
                            <option value="">{{ __('manager_queue.setup.language_auto') }}</option>
                            @foreach($contentLanguages as $language)
                                <option value="{{ $language['code'] }}">{{ $language['name'] }} ({{ $language['label'] }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>

                <fieldset class="field">
                    <legend>{{ __('manager_queue.setup.shows') }}</legend>
                    <div class="segmented" role="group">
                        @foreach(['branch', 'department', 'service_point'] as $option)
                            <button type="button" wire:click="$set('scope', '{{ $option }}')" aria-pressed="{{ $scope === $option ? 'true' : 'false' }}">{{ __('manager_queue.setup.scope.'.$option) }}</button>
                        @endforeach
                    </div>
                </fieldset>

                @if($scope === 'department')
                    <x-ui.field :label="__('manager_queue.setup.department')" for="display-department" name="department" required>
                        <select id="display-department" wire:model="department">
                            <option value="">{{ __('manager_queue.setup.choose') }}</option>
                            @foreach($departments as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @elseif($scope === 'service_point')
                    <x-ui.field :label="__('manager_queue.setup.desk')" for="display-point" name="point" required>
                        <select id="display-point" wire:model="point">
                            <option value="">{{ __('manager_queue.setup.choose') }}</option>
                            @foreach($points as $option)
                                <option value="{{ $option['uuid'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                <x-ui.field :label="__('manager_queue.setup.recent')" for="display-recent" name="recent" :help="__('manager_queue.setup.recent_help')">
                    <input id="display-recent" type="number" min="1" max="20" wire:model="recent">
                </x-ui.field>

                @if(count($rotationChoices) > 1)
                    <fieldset class="field queue-rotation">
                        <legend>{{ __('manager_queue.setup.rotation_title') }}</legend>
                        <label class="check-row">
                            <input type="checkbox" class="switch" wire:model.live="languageRotation">
                            <span>{{ __('manager_queue.setup.rotate_label') }}</span>
                        </label>
                        @if($languageRotation)
                            <div class="chip-select" role="group" aria-label="{{ __('manager_queue.setup.rotation_languages') }}">
                                @foreach($rotationChoices as $language)
                                    <label class="chip-toggle">
                                        <input type="checkbox" value="{{ $language['code'] }}" wire:model="rotationLocales">
                                        <span>{{ $language['label'] }} · {{ $language['name'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('rotationLocales')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            <x-ui.field :label="__('manager_queue.setup.rotation_seconds')" for="display-rotation" name="rotationSeconds" :help="__('manager_queue.setup.rotation_help', ['min' => $rotationMin, 'max' => $rotationMax])">
                                <input id="display-rotation" type="number" min="{{ $rotationMin }}" max="{{ $rotationMax }}" step="1" inputmode="numeric" wire:model="rotationSeconds">
                            </x-ui.field>
                        @endif
                    </fieldset>
                @endif

                <div class="stack stack--sm">
                    <label class="check-row">
                        <input type="checkbox" wire:model="sound">
                        <span>{{ __('manager_queue.setup.sound_label') }}</span>
                    </label>
                    <label class="check-row" @unless($voiceEntitled) title="{{ __('manager_queue.setup.voice_locked') }}" @endunless>
                        <input type="checkbox" wire:model.live="voice" @disabled(! $voiceEntitled)>
                        <span>{{ __('manager_queue.setup.voice_label') }}@unless($voiceEntitled) <span class="badge"><x-ui.icon name="lock" size="12" />{{ __('ui.manager_nav.locked') }}</span>@endunless</span>
                    </label>
                    @if($voiceEntitled && $voice)
                        <fieldset class="field">
                            <legend>{{ __('manager_queue.setup.voice_languages') }}</legend>
                            <div class="chip-select">
                                @foreach($languages as $language)
                                    <label class="chip-toggle">
                                        <input type="checkbox" value="{{ $language['code'] }}" wire:model="voiceLocales">
                                        <span>{{ $language['name'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="field-help">{{ __('manager_queue.setup.voice_help') }}</p>
                        </fieldset>
                    @endif
                    <label class="check-row">
                        <input type="checkbox" wire:model="active">
                        <span>{{ __('manager_queue.setup.display_active') }}</span>
                    </label>
                </div>
            </div>

            <x-slot:footer>
                <span class="drawer__spacer"></span>
                <x-ui.button variant="ghost" wire:click="closePanel">{{ __('ui.actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('ui.actions.save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif

    @if($mediaFor !== '')
        <livewire:center.queue.display-media :display="$mediaFor" :key="'display-media-'.$mediaFor" />
    @endif

    @if($screens)
        {{-- The real screen page at a television's size, scaled to fit: landscape or portrait, as it runs or pinned to any of the center's languages. --}}
        <div class="preview-shell queue-preview" x-data="queueDisplayPreview"
             x-on:queue-display-preview.window="show($event.detail)"
             x-show="open" x-cloak x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="queue-preview-title" x-trap.noscroll="open">
            <div class="preview-shell__bar">
                <strong id="queue-preview-title" x-text="name"></strong>
                <div class="segmented" role="group" aria-label="{{ __('manager_queue.preview.shape') }}">
                    <button type="button" x-on:click="shape = 'landscape'" :aria-pressed="shape === 'landscape' ? 'true' : 'false'"><x-ui.icon name="monitor" size="16" /><span>{{ __('manager_queue.preview.landscape') }}</span></button>
                    <button type="button" x-on:click="shape = 'portrait'" :aria-pressed="shape === 'portrait' ? 'true' : 'false'"><x-ui.icon name="tablet" size="16" /><span>{{ __('manager_queue.preview.portrait') }}</span></button>
                </div>
                <div class="segmented" role="group" aria-label="{{ __('manager_queue.preview.language') }}" x-show="langs.length > 1">
                    <button type="button" x-show="rotates" x-on:click="pick('')" :aria-pressed="lang === '' ? 'true' : 'false'"><x-ui.icon name="languages" size="16" /><span>{{ __('manager_queue.preview.rotating') }}</span></button>
                    <template x-for="option in langs" :key="option.code">
                        <button type="button" x-on:click="pick(option.code)" :aria-pressed="pressed(option.code) ? 'true' : 'false'" x-text="option.label"></button>
                    </template>
                </div>
                <label class="check-row queue-preview__sample" title="{{ __('manager_queue.preview.sample_hint') }}">
                    <input type="checkbox" class="switch" x-model="sample" x-on:change="stamp = Date.now()">
                    <span>{{ __('manager_queue.preview.sample') }}</span>
                </label>
                <button class="icon-button" type="button" x-on:click="open = false" aria-label="{{ __('ui.actions.close') }}" title="{{ __('ui.actions.close') }}"><x-ui.icon name="close" /></button>
            </div>
            <div class="preview-shell__stage queue-preview__stage" x-ref="stage">
                <template x-if="open">
                    <div class="queue-preview__screen" :data-shape="shape" :style="{ width: fit.boxWidth + 'px', height: fit.boxHeight + 'px' }">
                        <iframe class="queue-preview__frame" :width="fit.width" :height="fit.height"
                                :style="{ width: fit.width + 'px', height: fit.height + 'px', transform: 'scale(' + fit.scale + ')' }"
                                :src="src()" :title="name"></iframe>
                    </div>
                </template>
            </div>
        </div>
    @endif
</div>
