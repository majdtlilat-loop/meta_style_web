@php
    use App\Modules\LandingCms\Domain\LandingContent;

    $type = $section['type'];
    $path = 'content.sections.'.$id;
    $withItems = in_array($type, ['features', 'modules', 'steps', 'benefits', 'faq'], true);
    $withMedia = in_array($type, ['text_media', 'benefits', 'features'], true);
    $withCta = in_array($type, ['cta', 'contact', 'text_media', 'features', 'modules'], true);
    $withLayout = in_array($type, ['features', 'modules', 'benefits', 'steps'], true);
    $url = fn (?string $media) => $media ? \Illuminate\Support\Facades\Storage::disk('public')->url($media) : null;
    $locale = app()->getLocale();
    $itemTitle = fn (array $value) => trim((string) ($value[$locale] ?? '')) !== '' ? $value[$locale] : ($value['en'] ?? '');
@endphp

<x-ui.card :title="__('sadmin_cms.types.'.$type)">
    <x-slot:actions>
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="{{ $path }}.enabled"><span>{{ __('sadmin_cms.fields.show_section') }}</span></label>
        <button class="button button--ghost button--sm" type="button" wire:click="duplicateSection('{{ $id }}')"><x-ui.icon name="copy" size="16" />{{ __('sadmin_cms.actions.duplicate') }}</button>
        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="confirmRemoveSection('{{ $id }}')"><x-ui.icon name="trash" size="16" />{{ __('sadmin_cms.actions.remove_section') }}</button>
    </x-slot:actions>
    <div class="stack">
        <p class="field-help">{{ __('sadmin_cms.type_help.'.$type) }}</p>
        <div class="cms-grid-2">
            <div class="field">
                <label for="{{ $id }}-anchor">{{ __('sadmin_cms.fields.anchor') }}</label>
                <div class="input-affix"><span class="input-affix__prefix" dir="ltr">#</span><input id="{{ $id }}-anchor" dir="ltr" wire:model.blur="{{ $path }}.anchor" maxlength="40" autocomplete="off"></div>
                <p class="field-help">{{ __('sadmin_cms.fields.anchor_help') }}</p>
            </div>
            <div class="field">
                <label for="{{ $id }}-background">{{ __('sadmin_cms.fields.background') }}</label>
                <select id="{{ $id }}-background" wire:model.live="{{ $path }}.background">
                    @foreach(LandingContent::BACKGROUNDS as $background)<option value="{{ $background }}">{{ __('sadmin_cms.options.section_bg.'.$background) }}</option>@endforeach
                </select>
            </div>
        </div>

        <x-ui.lang-tabs :id="'section-'.$id" primary="en" :values="[
            $path.'.eyebrow' => $section['eyebrow'], $path.'.title' => $section['title'], $path.'.body' => $section['body'],
        ]" :fields="[
            ['name' => $path.'.eyebrow', 'label' => __('sadmin_cms.fields.eyebrow'), 'max' => 120],
            ['name' => $path.'.title', 'label' => __('sadmin_cms.fields.title'), 'max' => 190, 'counter' => true],
            ['name' => $path.'.body', 'label' => __('sadmin_cms.fields.body'), 'type' => 'textarea', 'rows' => 3, 'max' => 600, 'counter' => true],
        ]" />

        @if($withLayout)
            <div class="cms-grid-2">
                <div class="field">
                    <label for="{{ $id }}-layout">{{ __('sadmin_cms.fields.layout') }}</label>
                    <select id="{{ $id }}-layout" wire:model.live="{{ $path }}.layout">
                        @foreach(['grid', 'list', 'split'] as $layout)<option value="{{ $layout }}">{{ __('sadmin_cms.options.layout.'.$layout) }}</option>@endforeach
                    </select>
                </div>
                @if($section['layout'] === 'grid')
                    <div class="field">
                        <label for="{{ $id }}-columns">{{ __('sadmin_cms.fields.columns') }}</label>
                        <select id="{{ $id }}-columns" wire:model="{{ $path }}.columns">
                            @foreach([2, 3, 4] as $columns)<option value="{{ $columns }}">{{ $columns }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>
        @endif

        @if($type === 'pricing')
            <div class="cms-grid-2">
                <div class="field">
                    <label for="{{ $id }}-cycle">{{ __('sadmin_cms.fields.default_cycle') }}</label>
                    <select id="{{ $id }}-cycle" wire:model="{{ $path }}.options.default_cycle">
                        @foreach(['monthly', 'yearly'] as $cycle)<option value="{{ $cycle }}">{{ \App\View\Label::for('billing_period', $cycle) }}</option>@endforeach
                    </select>
                </div>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $path }}.options.show_comparison_link"><span>{{ __('sadmin_cms.fields.show_comparison_link') }}</span></label>
            </div>
            <div class="notice" data-tone="info"><x-ui.icon name="plans" /><p>{{ __('sadmin_cms.pricing_note') }} <a class="text-button" href="{{ route('superadmin.plans.index') }}" wire:navigate>{{ __('sadmin_shell.nav.plans') }}</a></p></div>
        @elseif($type === 'comparison')
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="{{ $path }}.options.show_limits"><span>{{ __('sadmin_cms.fields.show_limits') }}</span></label>
            <div class="notice" data-tone="info"><x-ui.icon name="plans" /><p>{{ __('sadmin_cms.comparison_note') }}</p></div>
        @elseif($type === 'contact')
            <div class="notice" data-tone="info"><x-ui.icon name="mail" /><p>{{ __('sadmin_cms.contact_note') }} <button class="text-button" type="button" wire:click="setPanel('footer')">{{ __('sadmin_cms.panels.footer') }}</button></p></div>
        @endif
    </div>
</x-ui.card>

@if($section['background'] === 'image')
    <x-ui.card :title="__('sadmin_cms.media_ui.background_image')">
        @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'sections.'.$id.'.background_image', 'path' => $section['background_image'], 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.background_image'), 'poster' => null])
    </x-ui.card>
@endif

@if($withMedia)
    <x-ui.card :title="__('sadmin_cms.media_ui.title')">
        <div class="stack">
            <div class="cms-media-grid">
                @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'sections.'.$id.'.image', 'path' => $section['image'], 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.image'), 'poster' => null])
                @if($type === 'text_media')
                    @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'sections.'.$id.'.video', 'path' => $section['video'], 'kind' => 'video', 'label' => __('sadmin_cms.media_ui.video'), 'poster' => $url($section['poster'])])
                    @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'sections.'.$id.'.poster', 'path' => $section['poster'], 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.poster'), 'poster' => null])
                @endif
            </div>
            <div class="cms-grid-2">
                <div class="field">
                    <label for="{{ $id }}-media-position">{{ __('sadmin_cms.fields.media_position') }}</label>
                    <select id="{{ $id }}-media-position" wire:model="{{ $path }}.media_position">
                        @foreach(['start', 'end'] as $position)<option value="{{ $position }}">{{ __('sadmin_cms.options.media.'.$position) }}</option>@endforeach
                    </select>
                </div>
            </div>
            <x-ui.lang-tabs :id="'section-alt-'.$id" primary="en" :values="[$path.'.image_alt' => $section['image_alt']]" :fields="[
                ['name' => $path.'.image_alt', 'label' => __('sadmin_cms.fields.alt'), 'max' => 190],
            ]" />
        </div>
    </x-ui.card>
@endif

@if($withCta)
    <x-ui.card :title="__('sadmin_cms.fields.section_cta')">
        <div class="cms-item-list">
            @include('livewire.sadmin.cms.partials.cta', ['path' => $path.'.cta', 'title' => __('sadmin_cms.fields.section_cta'), 'cta' => $section['cta']])
        </div>
    </x-ui.card>
@endif

@if($withItems)
    <x-ui.card :title="$type === 'faq' ? __('sadmin_cms.faq_items') : __('sadmin_cms.items')">
        <x-slot:actions>
            <button class="button button--secondary button--sm" type="button" wire:click="addSectionItem('{{ $id }}')" @disabled(count($section['items']) >= 20)><x-ui.icon name="plus" size="16" />{{ $type === 'faq' ? __('sadmin_cms.actions.add_question') : __('sadmin_cms.actions.add_item') }}</button>
        </x-slot:actions>
        <div class="cms-item-list">
            @forelse($section['items'] as $index => $item)
                @php
                    $itemPath = $path.'.items.'.$index;
                    $title = $itemTitle($item['title'] ?? []);
                @endphp
                <div class="cms-item" wire:key="{{ $id }}-item-{{ $index }}-{{ count($section['items']) }}" x-data="{ open: {{ trim((string) ($item['title']['en'] ?? '')) === '' ? 'true' : 'false' }} }" @if(! ($item['enabled'] ?? true)) data-disabled="true" @endif>
                    <div class="cms-item__head">
                        <button class="cms-item__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'">
                            <x-ui.icon name="chevron-right" size="16" class="cms-item__chevron" />
                            @if($type !== 'faq')<span class="cms-item__icon" aria-hidden="true"><x-ui.icon :name="$item['icon'] ?? 'sparkles'" size="16" /></span>@endif
                            <span class="cms-item__title">{{ $title !== '' ? $title : __('sadmin_cms.untitled_item') }}</span>
                        </button>
                        <div class="cms-item__tools">
                            <button class="icon-button icon-button--sm" type="button" wire:click="moveSectionItem('{{ $id }}', {{ $index }}, -1)" @disabled($index === 0) aria-label="{{ __('sadmin_cms.actions.move_up') }}" title="{{ __('sadmin_cms.actions.move_up') }}"><x-ui.icon name="arrow-up" /></button>
                            <button class="icon-button icon-button--sm" type="button" wire:click="moveSectionItem('{{ $id }}', {{ $index }}, 1)" @disabled($loop->last) aria-label="{{ __('sadmin_cms.actions.move_down') }}" title="{{ __('sadmin_cms.actions.move_down') }}"><x-ui.icon name="arrow-down" /></button>
                            <label class="switch-label"><input class="switch" type="checkbox" role="switch" wire:model.live="{{ $itemPath }}.enabled" aria-label="{{ __('sadmin_cms.fields.enabled') }}"></label>
                            <button class="icon-button icon-button--sm icon-button--danger" type="button" wire:click="removeSectionItem('{{ $id }}', {{ $index }})" wire:confirm="{{ __('sadmin_cms.remove_confirm') }}" data-confirm-tone="danger" aria-label="{{ __('sadmin_cms.actions.remove') }}" title="{{ __('sadmin_cms.actions.remove') }}"><x-ui.icon name="trash" /></button>
                        </div>
                    </div>
                    <div class="cms-item__body stack stack--sm" x-show="open" x-cloak>
                        <x-ui.lang-tabs :id="$id.'-item-'.$index" primary="en" :values="[$itemPath.'.title' => $item['title'] ?? [], $itemPath.'.body' => $item['body'] ?? []]" :fields="[
                            ['name' => $itemPath.'.title', 'label' => $type === 'faq' ? __('sadmin_cms.fields.question') : __('sadmin_cms.fields.title'), 'max' => 190],
                            ['name' => $itemPath.'.body', 'label' => $type === 'faq' ? __('sadmin_cms.fields.answer') : __('sadmin_cms.fields.body'), 'type' => 'textarea', 'rows' => 3, 'max' => 600],
                        ]" />
                        @if($type !== 'faq')
                            <div class="cms-grid-2">
                                <fieldset class="field">
                                    <legend>{{ __('sadmin_cms.fields.icon') }}</legend>
                                    <div class="icon-picker" role="radiogroup">
                                        @foreach(LandingContent::ICONS as $icon)
                                            <label class="icon-picker__option" title="{{ $icon }}">
                                                <input class="sr-only" type="radio" name="{{ $id }}-{{ $index }}-icon" value="{{ $icon }}" wire:model.live="{{ $itemPath }}.icon">
                                                <x-ui.icon :name="$icon" size="18" /><span class="sr-only">{{ $icon }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <div class="stack stack--sm">
                                    <div class="field">
                                        <label for="{{ $id }}-{{ $index }}-url">{{ __('sadmin_cms.fields.item_link') }}</label>
                                        <input id="{{ $id }}-{{ $index }}-url" dir="ltr" wire:model="{{ $itemPath }}.url" placeholder="/register">
                                    </div>
                                    @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'sections.'.$id.'.items.'.$index.'.image', 'path' => $item['image'] ?? '', 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.item_image'), 'poster' => null])
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <x-ui.empty-state compact icon="layers" :title="__('sadmin_cms.empty.items')" />
            @endforelse
        </div>
    </x-ui.card>
@endif
