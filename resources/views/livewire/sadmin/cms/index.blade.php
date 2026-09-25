@php
    use App\Modules\LandingCms\Domain\LandingContent;

    $locale = app()->getLocale();
    $text = fn ($value) => is_array($value) ? (trim((string) ($value[$locale] ?? '')) !== '' ? $value[$locale] : (string) ($value['en'] ?? '')) : '';
    $live = $page->published_version;
    $inSync = $live !== null && (int) $live === (int) $page->draft_version;
    $typeIcons = ['features' => 'grid', 'modules' => 'layers', 'steps' => 'list', 'benefits' => 'check-circle', 'text_media' => 'image',
        'pricing' => 'plans', 'comparison' => 'layout', 'faq' => 'faq', 'cta' => 'zap', 'contact' => 'mail'];
    $order = $content['section_order'] ?? [];
    $currentSection = str_starts_with($activePanel, 'section:') ? substr($activePanel, 8) : null;
@endphp

<div class="cms-workspace stack">
    <x-ui.page-header :title="__('sadmin_cms.title')">
        <x-slot:meta>
            @if($live !== null)
                <x-ui.status value="published" :label="__('sadmin_cms.state.live', ['version' => $live])" />
                @if($page->published_at)<span class="muted">{{ __('sadmin_cms.state.published_at', ['date' => $page->published_at->translatedFormat('j M Y, H:i')]) }}</span>@endif
            @else
                <x-ui.status value="draft" :label="__('sadmin_cms.state.not_published')" />
            @endif
            @if(! $inSync)
                <x-ui.status value="pending" :label="__('sadmin_cms.state.unpublished')" :dot="false" />
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <a class="button button--ghost" href="{{ route('home') }}" target="_blank" rel="noopener"><x-ui.icon name="external" size="16" />{{ __('sadmin_cms.open_site') }}</a>
            <button class="button button--secondary" type="button" x-data x-on:click="$dispatch('cms-preview')"><x-ui.icon name="eye" size="16" />{{ __('ui.actions.preview') }}</button>
            <button class="button button--secondary" type="button" wire:click="save(false)" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('ui.actions.save_draft') }}</button>
            <button class="button" type="button" wire:click="$set('confirmingPublish', true)"><x-ui.icon name="rocket" size="16" />{{ __('ui.actions.publish') }}</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />
    @error('content')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>
    @enderror
    @error('mediaUpload')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>
    @enderror

    <div class="cms-layout">
        <nav class="cms-sections" aria-label="{{ __('sadmin_cms.editor_sections') }}">
            <p class="cms-sections__label">{{ __('sadmin_cms.groups.page') }}</p>
            @foreach(['header' => 'header', 'menu' => 'navigation', 'hero' => 'hero'] as $panel => $icon)
                <button type="button" wire:click="setPanel('{{ $panel }}')" @class(['cms-sections__item', 'is-active' => $activePanel === $panel]) aria-current="{{ $activePanel === $panel ? 'true' : 'false' }}">
                    <x-ui.icon :name="$icon" size="16" /><span>{{ __('sadmin_cms.panels.'.$panel) }}</span>
                    @if($panel === 'hero' && ! ($content['hero']['enabled'] ?? true))<span class="badge">{{ __('sadmin_cms.hidden') }}</span>@endif
                </button>
            @endforeach

            <p class="cms-sections__label">{{ __('sadmin_cms.groups.sections') }}</p>
            <ol class="cms-outline" aria-label="{{ __('sadmin_cms.groups.sections') }}">
                @foreach($order as $position => $id)
                    @php
                        $section = $content['sections'][$id] ?? null;
                        $label = $section ? ($text($section['title'] ?? []) ?: __('sadmin_cms.types.'.$section['type'])) : $id;
                    @endphp
                    @continue(! $section)
                    <li @class(['cms-outline__item', 'is-active' => $currentSection === $id, 'is-hidden' => ! ($section['enabled'] ?? true)]) wire:key="outline-{{ $id }}">
                        <button type="button" class="cms-outline__select" wire:click="setPanel('section:{{ $id }}')" aria-current="{{ $currentSection === $id ? 'true' : 'false' }}">
                            <x-ui.icon :name="$typeIcons[$section['type']] ?? 'layers'" size="16" />
                            <span class="cms-outline__text">
                                <span class="truncate">{{ $label }}</span>
                                <small>{{ __('sadmin_cms.types.'.$section['type']) }}@unless($section['enabled'] ?? true) · {{ __('sadmin_cms.hidden') }}@endunless</small>
                            </span>
                        </button>
                        <span class="cms-outline__tools">
                            <button class="icon-button icon-button--xs" type="button" wire:click="moveSection('{{ $id }}', -1)" @disabled($position === 0) aria-label="{{ __('sadmin_cms.actions.move_up') }}: {{ $label }}" title="{{ __('sadmin_cms.actions.move_up') }}"><x-ui.icon name="arrow-up" size="14" /></button>
                            <button class="icon-button icon-button--xs" type="button" wire:click="moveSection('{{ $id }}', 1)" @disabled($loop->last) aria-label="{{ __('sadmin_cms.actions.move_down') }}: {{ $label }}" title="{{ __('sadmin_cms.actions.move_down') }}"><x-ui.icon name="arrow-down" size="14" /></button>
                        </span>
                    </li>
                @endforeach
            </ol>
            <button class="button button--secondary button--sm button--block" type="button" wire:click="$set('choosingSection', true)"><x-ui.icon name="plus" size="16" />{{ __('sadmin_cms.actions.add_section') }}</button>

            <p class="cms-sections__label">{{ __('sadmin_cms.groups.site') }}</p>
            @foreach(['footer' => 'footer', 'seo' => 'seo', 'history' => 'history'] as $panel => $icon)
                <button type="button" wire:click="setPanel('{{ $panel }}')" @class(['cms-sections__item', 'is-active' => $activePanel === $panel]) aria-current="{{ $activePanel === $panel ? 'true' : 'false' }}">
                    <x-ui.icon :name="$icon" size="16" /><span>{{ __('sadmin_cms.panels.'.$panel) }}</span>
                    @if($panel === 'footer' && ! ($content['footer']['enabled'] ?? true))<span class="badge">{{ __('sadmin_cms.hidden') }}</span>@endif
                </button>
            @endforeach
        </nav>

        <div class="cms-editor" wire:loading.class="is-refreshing" wire:target="setPanel,loadRevision,addSection,duplicateSection,removeSection">
            @switch(true)
                @case($activePanel === 'header')
                    @include('livewire.sadmin.cms.panels.header')
                    @break
                @case($activePanel === 'menu')
                    @include('livewire.sadmin.cms.panels.menu')
                    @break
                @case($activePanel === 'footer')
                    @include('livewire.sadmin.cms.panels.footer')
                    @break
                @case($activePanel === 'seo')
                    @include('livewire.sadmin.cms.panels.seo')
                    @break
                @case($activePanel === 'history')
                    @include('livewire.sadmin.cms.panels.history')
                    @break
                @case($currentSection !== null && isset($content['sections'][$currentSection]))
                    @include('livewire.sadmin.cms.panels.section', ['id' => $currentSection, 'section' => $content['sections'][$currentSection]])
                    @break
                @default
                    @include('livewire.sadmin.cms.panels.hero')
            @endswitch
        </div>
    </div>

    {{-- ── Add a section ─────────────────────────────────────────────── --}}
    @if($choosingSection)
        <x-ui.modal :title="__('sadmin_cms.add_section.title')" icon="plus" close="$set('choosingSection', false)" size="lg">
            <div class="section-types">
                @foreach(LandingContent::SECTION_TYPES as $type)
                    <button type="button" class="section-type" wire:click="addSection('{{ $type }}')">
                        <span class="section-type__icon" aria-hidden="true"><x-ui.icon :name="$typeIcons[$type] ?? 'layers'" /></span>
                        <strong>{{ __('sadmin_cms.types.'.$type) }}</strong>
                        <small>{{ __('sadmin_cms.type_help.'.$type) }}</small>
                    </button>
                @endforeach
            </div>
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('choosingSection', false)">{{ __('ui.actions.cancel') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Remove a section ──────────────────────────────────────────── --}}
    @if($removingSection && isset($content['sections'][$removingSection]))
        <x-ui.modal :title="__('sadmin_cms.remove_section.title')" :description="__('sadmin_cms.remove_section.body')" icon="trash" tone="danger" close="$set('removingSection', null)" submit="removeSection">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('removingSection', null)">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit">{{ __('sadmin_cms.actions.remove_section') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Publish ───────────────────────────────────────────────────── --}}
    @if($confirmingPublish)
        <x-ui.modal :title="__('sadmin_cms.publish.title')" :description="__('sadmin_cms.publish.body')" icon="rocket" close="$set('confirmingPublish', false)" submit="save(true)">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('confirmingPublish', false)">{{ __('ui.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('sadmin_cms.publish.confirm') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ── Preview (the saved draft) ─────────────────────────────────── --}}
    <div class="preview-shell" x-data="{ open: false, device: 'desktop', lang: @js($locale), stamp: 0 }" x-on:cms-preview.window="open = true; stamp = Date.now()" x-show="open" x-cloak
         x-on:keydown.escape.window="open = false" role="dialog" aria-modal="true" aria-labelledby="cms-preview-title" x-trap.noscroll="open">
        <div class="preview-shell__bar">
            <strong id="cms-preview-title">{{ __('sadmin_cms.preview.title') }}</strong>
            <div class="segmented" role="group" aria-label="{{ __('sadmin_cms.preview.device') }}">
                @foreach(['desktop' => 'monitor', 'tablet' => 'tablet', 'mobile' => 'smartphone'] as $device => $icon)
                    <button type="button" x-on:click="device = '{{ $device }}'" :aria-pressed="device === '{{ $device }}' ? 'true' : 'false'"><x-ui.icon :name="$icon" size="16" /><span>{{ __('sadmin_cms.preview.'.$device) }}</span></button>
                @endforeach
            </div>
            <div class="segmented" role="group" aria-label="{{ __('sadmin_cms.preview.language') }}">
                @foreach(['en' => 'EN', 'ar' => 'AR', 'ckb' => 'KU'] as $code => $short)
                    <button type="button" x-on:click="lang = '{{ $code }}'" :aria-pressed="lang === '{{ $code }}' ? 'true' : 'false'">{{ $short }}</button>
                @endforeach
            </div>
            <span class="muted preview-shell__note">{{ __('sadmin_cms.preview.note') }}</span>
            <button class="icon-button" type="button" x-on:click="open = false" aria-label="{{ __('ui.actions.close') }}"><x-ui.icon name="close" /></button>
        </div>
        <div class="preview-shell__stage">
            <template x-if="open">
                <iframe class="preview-shell__frame" :data-device="device" :src="'{{ route('superadmin.cms.preview') }}?lang=' + lang + '&t=' + stamp" title="{{ __('sadmin_cms.preview.title') }}"></iframe>
            </template>
        </div>
    </div>
</div>
