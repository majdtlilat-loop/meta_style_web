<div class="cms-workspace stack sb-site" x-data="siteBuilder" x-on:input="markDirty" x-on:site-saved.window="dirty = false">
    <x-ui.page-header :title="__('manager_site.title')">
        <x-slot:meta>
            @if($state['published'])
                <x-ui.status value="published" :label="__('manager_site.state.live', ['version' => $state['published']['version']])" />
                @if($state['published']['at'])<span class="muted">{{ __('manager_site.state.published_at', ['date' => $state['published']['at']]) }}@if($state['published']['by']) · {{ $state['published']['by'] }}@endif</span>@endif
            @else
                <x-ui.status value="draft" :label="__('manager_site.state.not_published')" />
            @endif
            @if($state['pending'])
                <x-ui.status value="pending" :label="__('manager_site.state.pending')" :dot="false" />
            @endif
            <span class="sb-unsaved" hidden x-bind:hidden="! unsaved"><x-ui.status value="warning" :label="__('manager_site.state.unsaved')" :dot="false" /></span>
        </x-slot:meta>
        <x-slot:actions>
            <a class="button button--ghost" href="{{ $publicUrl }}" target="_blank" rel="noopener"><x-ui.icon name="external" size="16" />{{ __('manager_site.actions.open_site') }}</a>
            <a class="button button--ghost" href="{{ $brandUrl }}" wire:navigate><x-ui.icon name="palette" size="16" />{{ __('manager_site.actions.brand') }}</a>
            @if($canManage)
                <button class="button button--secondary" type="button" wire:click="preview" wire:loading.attr="data-loading" wire:target="preview"><x-ui.icon name="eye" size="16" />{{ __('manager_site.actions.preview') }}</button>
                <button class="button button--secondary" type="button" wire:click="saveDraft" wire:loading.attr="data-loading" wire:target="saveDraft"><x-ui.icon name="save" size="16" />{{ __('manager_site.actions.save_draft') }}</button>
                <button class="button" type="button" wire:click="$set('confirmingPublish', true)"><x-ui.icon name="rocket" size="16" />{{ __('manager_site.actions.publish') }}</button>
            @else
                <button class="button button--secondary" type="button" x-on:click="$dispatch('site-preview-open')"><x-ui.icon name="eye" size="16" />{{ __('manager_site.actions.preview') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />
    @error('content')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>
    @enderror
    @unless($canManage)
        <x-ui.notice tone="info" :message="__('manager_site.read_only')" />
    @endunless
    @if($state['draft'] && $state['draft']['restored_from'])
        <x-ui.notice tone="info" :message="__('manager_site.state.restored_draft', ['version' => $state['draft']['restored_from']])" />
    @endif

    <div class="cms-layout sb-layout">
        <nav class="cms-sections sb-rail" aria-label="{{ __('manager_site.rail.label') }}">
            <p class="cms-sections__label">{{ __('manager_site.rail.page') }}</p>
            @foreach(['header' => 'header', 'navigation' => 'navigation', 'hero' => 'hero'] as $key => $icon)
                <button type="button" wire:click="setPanel('{{ $key }}')" @class(['cms-sections__item', 'is-active' => $panel === $key]) aria-current="{{ $panel === $key ? 'true' : 'false' }}">
                    <x-ui.icon :name="$icon" size="16" /><span>{{ __('manager_site.panels.'.$key) }}</span>
                    @if($key === 'hero' && ! ($content['hero']['enabled'] ?? true))<span class="badge">{{ __('manager_site.hidden') }}</span>@endif
                </button>
            @endforeach

            <p class="cms-sections__label">{{ __('manager_site.rail.sections') }}</p>
            @if($outline === [])
                <p class="field-help sb-rail__empty">{{ __('manager_site.rail.empty') }}</p>
            @endif
            <ol class="cms-outline" @if($canManage) x-data x-sortable="sortSections" data-sortable-group="site-sections" @endif aria-label="{{ __('manager_site.rail.sections') }}">
                @foreach($outline as $position => $row)
                    <li @class(['cms-outline__item', 'is-active' => $current === $row['id'], 'is-hidden' => ! $row['enabled']]) data-sortable-item="{{ $row['id'] }}" wire:key="outline-{{ $row['id'] }}">
                        @if($canManage)
                            <button type="button" class="icon-button icon-button--xs sb-grip" data-sortable-handle aria-label="{{ __('manager_site.actions.drag', ['name' => $row['label']]) }}"><x-ui.icon name="grip" size="14" /></button>
                        @endif
                        <button type="button" class="cms-outline__select" wire:click="setPanel('section:{{ $row['id'] }}')" aria-current="{{ $current === $row['id'] ? 'true' : 'false' }}">
                            <x-ui.icon :name="$row['icon']" size="16" />
                            <span class="cms-outline__text">
                                <span class="truncate">{{ $row['label'] }}</span>
                                <small>{{ $row['type_label'] }}@unless($row['enabled']) · {{ __('manager_site.hidden') }}@endunless</small>
                            </span>
                        </button>
                        @if($canManage)
                            <span class="cms-outline__tools">
                                <button class="icon-button icon-button--xs" type="button" wire:click="moveSection('{{ $row['id'] }}', -1)" @disabled($position === 0) aria-label="{{ __('manager_site.actions.move_up') }}: {{ $row['label'] }}" title="{{ __('manager_site.actions.move_up') }}"><x-ui.icon name="arrow-up" size="14" /></button>
                                <button class="icon-button icon-button--xs" type="button" wire:click="moveSection('{{ $row['id'] }}', 1)" @disabled($loop->last) aria-label="{{ __('manager_site.actions.move_down') }}: {{ $row['label'] }}" title="{{ __('manager_site.actions.move_down') }}"><x-ui.icon name="arrow-down" size="14" /></button>
                                <button class="icon-button icon-button--xs" type="button" wire:click="toggleSection('{{ $row['id'] }}')" aria-label="{{ $row['enabled'] ? __('manager_site.actions.hide') : __('manager_site.actions.show') }}: {{ $row['label'] }}" title="{{ $row['enabled'] ? __('manager_site.actions.hide') : __('manager_site.actions.show') }}"><x-ui.icon :name="$row['enabled'] ? 'eye' : 'eye-off'" size="14" /></button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if($canManage)
                <button class="button button--secondary button--sm button--block" type="button" wire:click="$set('choosingSection', true)"><x-ui.icon name="plus" size="16" />{{ __('manager_site.actions.add_section') }}</button>
            @endif

            <p class="cms-sections__label">{{ __('manager_site.rail.site') }}</p>
            @foreach(['footer' => 'footer', 'seo' => 'seo', 'history' => 'history'] as $key => $icon)
                <button type="button" wire:click="setPanel('{{ $key }}')" @class(['cms-sections__item', 'is-active' => $panel === $key]) aria-current="{{ $panel === $key ? 'true' : 'false' }}">
                    <x-ui.icon :name="$icon" size="16" /><span>{{ __('manager_site.panels.'.$key) }}</span>
                    @if($key === 'footer' && ! ($content['footer']['enabled'] ?? true))<span class="badge">{{ __('manager_site.hidden') }}</span>@endif
                </button>
            @endforeach
        </nav>

        <div class="cms-editor" wire:loading.class="is-refreshing" wire:target="setPanel,addSection,duplicateSection,removeSection,reload">
            <fieldset class="sb-fieldset" @disabled(! $canManage)>
                <legend class="sr-only">{{ __('manager_site.title') }}</legend>
                @if($panel === 'header')
                    @include('livewire.center.appearance.site.panels.header')
                @elseif($panel === 'navigation')
                    @include('livewire.center.appearance.site.panels.navigation')
                @elseif($panel === 'footer')
                    @include('livewire.center.appearance.site.panels.footer')
                @elseif($panel === 'seo')
                    @include('livewire.center.appearance.site.panels.seo')
                @elseif($panel === 'history')
                    <livewire:center.appearance.site-history wire:key="site-history" />
                @elseif($current !== null && $section !== null)
                    @include('livewire.center.appearance.site.panels.section', ['id' => $current, 'path' => 'content.sections.'.$current, 'target' => 'sections.'.$current])
                @else
                    @include('livewire.center.appearance.site.panels.hero')
                @endif
            </fieldset>
        </div>
    </div>

    {{-- ── Add a section ─────────────────────────────────────────────────── --}}
    @if($choosingSection && $canManage)
        <x-ui.modal :title="__('manager_site.add_section.title')" icon="plus" close="$set('choosingSection', false)" size="lg">
            <div class="section-types">
                @foreach($choices['types'] as $type => $meta)
                    <button type="button" class="section-type" wire:click="addSection('{{ $type }}')" wire:key="add-{{ $type }}">
                        <span class="section-type__icon" aria-hidden="true"><x-ui.icon :name="$meta['icon']" /></span>
                        <strong>{{ $meta['label'] }}</strong>
                        <small>{{ $meta['help'] }}</small>
                    </button>
                @endforeach
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('choosingSection', false)">{{ __('ui.actions.cancel') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Remove a section ──────────────────────────────────────────────── --}}
    @if($removingSection && isset($content['sections'][$removingSection]))
        <x-ui.modal :title="__('manager_site.remove_section.title')" :description="__('manager_site.remove_section.body')" icon="trash" tone="danger" close="$set('removingSection', null)" submit="removeSection">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('removingSection', null)">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit">{{ __('manager_site.actions.remove_section') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Publish ───────────────────────────────────────────────────────── --}}
    @if($confirmingPublish && $canManage)
        <x-ui.modal :title="__('manager_site.publish.title')" :description="__('manager_site.publish.body')" icon="rocket" close="$set('confirmingPublish', false)" submit="publish">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('confirmingPublish', false)">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="publish">{{ __('manager_site.publish.confirm') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Leaving with unsaved edits (in-app navigation) ───────────────── --}}
    <div class="modal-backdrop" hidden x-bind:hidden="! leavingTo" x-on:click.self="stay()" x-on:keydown.escape.window="leavingTo && stay()">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="site-leave-title" x-trap.noscroll="leavingTo">
            <div class="modal__body">
                <span class="modal__icon" data-tone="warning" aria-hidden="true"><x-ui.icon name="alert-triangle" /></span>
                <div><h2 id="site-leave-title">{{ __('manager_site.leave.title') }}</h2></div>
            </div>
            <div class="modal__footer">
                <button class="button button--secondary" type="button" x-on:click="stay()">{{ __('manager_site.leave.stay') }}</button>
                <button class="button button--danger" type="button" x-on:click="leave()">{{ __('manager_site.leave.leave') }}</button>
            </div>
        </div>
    </div>

    {{-- ── Preview: the saved draft, in a frame, per device and language ─── --}}
    <div class="preview-shell" x-data="{ open: false, device: 'desktop', lang: @js($primary), stamp: 0 }" x-on:site-preview-open.window="open = true; stamp = Date.now()" x-show="open" x-cloak
         x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="site-preview-title" x-trap.noscroll="open">
        <div class="preview-shell__bar">
            <strong id="site-preview-title">{{ __('manager_site.preview.title') }}</strong>
            <div class="segmented" role="group" aria-label="{{ __('manager_site.preview.device') }}">
                @foreach(['desktop' => 'monitor', 'tablet' => 'tablet', 'mobile' => 'smartphone'] as $device => $icon)
                    <button type="button" x-on:click="device = '{{ $device }}'" :aria-pressed="device === '{{ $device }}' ? 'true' : 'false'"><x-ui.icon :name="$icon" size="16" /><span>{{ __('manager_site.preview.'.$device) }}</span></button>
                @endforeach
            </div>
            @if(count($previewLocales) > 1)
                <div class="segmented" role="group" aria-label="{{ __('manager_site.preview.language') }}">
                    @foreach($previewLocales as $option)
                        <button type="button" x-on:click="lang = '{{ $option['code'] }}'" :aria-pressed="lang === '{{ $option['code'] }}' ? 'true' : 'false'">{{ $option['short'] }}</button>
                    @endforeach
                </div>
            @endif
            <span class="muted preview-shell__note">{{ __('manager_site.preview.note') }}</span>
            <button class="icon-button" type="button" x-on:click="open = false" aria-label="{{ __('ui.actions.close') }}"><x-ui.icon name="close" /></button>
        </div>
        <div class="preview-shell__stage">
            <template x-if="open">
                <iframe class="preview-shell__frame" :data-device="device" :src="@js($previewUrl) + '?lang=' + lang + '&t=' + stamp" title="{{ __('manager_site.preview.title') }}"></iframe>
            </template>
        </div>
    </div>
</div>
