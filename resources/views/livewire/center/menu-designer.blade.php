{{--
    Manager → Appearance → Menu.

    Every control is a choice from config/menu.php. There is deliberately no
    HTML, CSS or JavaScript field: a center-authored script on a page guests
    open is stored XSS against that center's own customers
    (docs/13-ROADMAP.md Phase 4 §14). Labels come from MenuOptions, already
    translated — no raw key reaches this page.
--}}
<div class="stack appearance-page">
    <x-ui.page-header :title="__('manager_appearance.menu.title')">
        <x-slot:meta>
            @if ($published)
                <x-ui.status tone="success" :label="__('manager_appearance.menu.live_version', ['version' => $published['version']])" />
                @if ($published['at'])
                    <span class="muted">{{ __('manager_appearance.menu.published_at', ['date' => $published['at']]) }}</span>
                @endif
            @else
                <x-ui.status tone="neutral" :label="__('manager_appearance.menu.not_published')" />
            @endif
            @if ($dirty)
                <x-ui.status tone="warning" :label="__('manager_appearance.menu.unpublished_changes')" />
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <button class="button button--secondary" type="button" wire:click="preview" wire:loading.attr="data-loading" wire:target="preview">
                <x-ui.icon name="eye" size="16" />{{ __('manager_appearance.actions.preview') }}
            </button>
            @if ($publicUrl)
                <a class="button button--ghost" href="{{ $publicUrl }}" target="_blank" rel="noopener">
                    <x-ui.icon name="external" size="16" />{{ __('manager_appearance.menu.open_live') }}
                </a>
            @endif
            @if ($canManage)
                <button class="button button--secondary" type="button" wire:click="saveDraft" wire:loading.attr="data-loading" wire:target="saveDraft">
                    <x-ui.icon name="save" size="16" />{{ __('manager_appearance.menu.save_draft') }}
                </button>
                <button class="button" type="button" wire:click="confirmPublish">
                    <x-ui.icon name="rocket" size="16" />{{ __('manager_appearance.menu.publish') }}
                </button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="$set('notice', '')" />
    @error('theme')<x-ui.notice :message="$message" tone="danger" />@enderror
    @error('sections')<x-ui.notice :message="$message" tone="danger" />@enderror

    @unless ($canManage)
        <div class="notice" data-tone="info" role="status"><x-ui.icon name="lock" /><p>{{ __('manager_appearance.menu.read_only') }}</p></div>
    @endunless

    <div class="appearance-layout">
        <div class="appearance-main stack">
            {{-- Template --}}
            <x-ui.card :title="__('manager_appearance.menu.template.title')">
                <x-slot:actions><span class="info-tip" role="img" tabindex="0" title="{{ __('manager_appearance.menu.template.description') }}" aria-label="{{ __('manager_appearance.menu.template.description') }}"><x-ui.icon name="info" size="16" /></span></x-slot:actions>
                <div class="template-grid" role="radiogroup" aria-label="{{ __('manager_appearance.menu.template.title') }}">
                    @foreach ($templates as $template)
                        <label class="template-card" wire:key="template-{{ $template['key'] }}">
                            <input class="sr-only" type="radio" name="menu-template" value="{{ $template['key'] }}" wire:model.live="templateKey" @disabled(! $canManage)>
                            <span class="template-card__swatch" data-dark="{{ $template['dark'] ? 'true' : 'false' }}" style="--swatch-primary: {{ $template['primary'] }}; --swatch-accent: {{ $template['accent'] }};" aria-hidden="true"><span></span><span></span><span></span></span>
                            <strong>{{ $template['label'] }}</strong>
                        </label>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Colours --}}
            <x-ui.card :title="__('manager_appearance.menu.colours.title')">
                <div class="field">
                    <span class="field-label" id="menu-colour-source">{{ __('manager_appearance.menu.options.colors_source.label') }}</span>
                    <div class="segmented" role="radiogroup" aria-labelledby="menu-colour-source">
                        @foreach (['menu', 'brand'] as $source)
                            <label @class(['is-active' => ($theme['colors_source'] ?? 'menu') === $source])>
                                <input class="sr-only" type="radio" name="menu-colors-source" value="{{ $source }}" wire:model.live="theme.colors_source" @disabled(! $canManage)>{{ __('manager_appearance.menu.options.colors_source.values.'.$source) }}
                            </label>
                        @endforeach
                    </div>
                    @if (($theme['colors_source'] ?? 'menu') === 'brand')
                        <p class="field-help">{{ $brandAvailable ? __('manager_appearance.menu.colours.brand_on') : __('manager_appearance.menu.colours.brand_missing') }}</p>
                    @endif
                </div>
                <div class="form-grid">
                    @foreach (['primary', 'accent'] as $colour)
                        <div class="color-field">
                            <label for="menu-colour-{{ $colour }}">{{ __('manager_appearance.menu.colours.'.$colour) }}</label>
                            <div class="color-field__control">
                                <input type="color" id="menu-colour-{{ $colour }}" wire:model.live.debounce.300ms="theme.{{ $colour }}" @disabled(! $canManage) aria-describedby="menu-colour-{{ $colour }}-hex">
                                <input type="text" id="menu-colour-{{ $colour }}-hex" class="mono" dir="ltr" maxlength="7" wire:model.blur="theme.{{ $colour }}" @disabled(! $canManage) aria-label="{{ __('manager_appearance.menu.colours.'.$colour) }} (HEX)">
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Layout, typography, buttons --}}
            @foreach ($groups as $group => $options)
                <x-ui.card :title="__('manager_appearance.menu.groups.'.$group.'.title')" wire:key="group-{{ $group }}">
                    <div class="form-grid">
                        @foreach ($options as $option)
                            @continue($option['key'] === 'gradient_angle' && ($theme['hero_style'] ?? 'plain') !== 'gradient')
                            <div class="field" wire:key="option-{{ $option['key'] }}">
                                @if (count($option['options']) <= 3)
                                    <span class="field-label" id="menu-option-{{ $option['key'] }}">{{ $option['label'] }}</span>
                                    <div class="segmented segmented--scroll" role="radiogroup" aria-labelledby="menu-option-{{ $option['key'] }}">
                                        @foreach ($option['options'] as $value => $label)
                                            <label @class(['is-active' => ($theme[$option['key']] ?? '') === (string) $value])>
                                                <input class="sr-only" type="radio" name="menu-option-{{ $option['key'] }}" value="{{ $value }}" wire:model.live="theme.{{ $option['key'] }}" @disabled(! $canManage)>{{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                @else
                                    <label for="menu-option-{{ $option['key'] }}">{{ $option['label'] }}</label>
                                    <select id="menu-option-{{ $option['key'] }}" wire:model.live="theme.{{ $option['key'] }}" @disabled(! $canManage)>
                                        @foreach ($option['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if ($option['help'])<p class="field-help">{{ $option['help'] }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        <aside class="appearance-aside stack">
            {{-- Sections: order and visibility --}}
            <x-ui.card :title="__('manager_appearance.menu.sections_title')" flush>
                <ol class="menu-sections" x-data @if ($canManage) x-sortable="moveSection" @endif data-sortable-group="menu-sections" data-sortable-container="menu">
                    @foreach ($sectionRows as $index => $row)
                        <li class="menu-section" data-sortable-item="{{ $row['key'] }}" wire:key="section-{{ $row['key'] }}" @if (! ($sections[$index]['visible'] ?? false)) data-hidden @endif>
                            <div class="menu-section__head">
                                @if ($canManage)
                                    <button type="button" class="icon-button icon-button--sm" data-sortable-handle aria-label="{{ __('manager_appearance.menu.drag', ['section' => $row['label']]) }}" title="{{ __('manager_appearance.menu.drag', ['section' => $row['label']]) }}"><x-ui.icon name="grip" /></button>
                                @endif
                                <label class="menu-section__toggle">
                                    <input type="checkbox" class="switch" role="switch" wire:model.live="sections.{{ $index }}.visible" @disabled(! $canManage) aria-label="{{ __('manager_appearance.menu.show_section', ['section' => $row['label']]) }}">
                                    <span title="{{ $row['help'] }}"><strong>{{ $row['label'] }}</strong></span>
                                </label>
                                @if ($canManage)
                                    <span class="menu-section__move">
                                        <button type="button" class="icon-button icon-button--sm" wire:click="moveSection('{{ $row['key'] }}', {{ $index - 1 }})" @disabled($index === 0) aria-label="{{ __('manager_appearance.menu.move_up', ['section' => $row['label']]) }}" title="{{ __('manager_appearance.actions.move_up') }}"><x-ui.icon name="arrow-up" /></button>
                                        <button type="button" class="icon-button icon-button--sm" wire:click="moveSection('{{ $row['key'] }}', {{ $index + 1 }})" @disabled($loop->last) aria-label="{{ __('manager_appearance.menu.move_down', ['section' => $row['label']]) }}" title="{{ __('manager_appearance.actions.move_down') }}"><x-ui.icon name="arrow-down" /></button>
                                    </span>
                                @endif
                            </div>
                            @if ($row['settings'] !== [] && ($sections[$index]['visible'] ?? false))
                                <div class="menu-section__settings">
                                    @foreach ($row['settings'] as $setting)
                                        @if ($setting['type'] === 'bool')
                                            <label class="choice choice--inline">
                                                <input type="checkbox" wire:model.live="sections.{{ $index }}.config.{{ $setting['key'] }}" @disabled(! $canManage)>
                                                <span>{{ $setting['label'] }}</span>
                                            </label>
                                        @elseif ($setting['type'] === 'choice')
                                            <label class="menu-section__select">
                                                <span>{{ $setting['label'] }}</span>
                                                <select wire:model.live="sections.{{ $index }}.config.{{ $setting['key'] }}" @disabled(! $canManage)>
                                                    @foreach ($setting['options'] as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @else
                                            <label class="menu-section__select">
                                                <span>{{ $setting['label'] }}</span>
                                                <input type="number" inputmode="numeric" min="{{ $setting['min'] }}" max="{{ $setting['max'] }}" wire:model.blur="sections.{{ $index }}.config.{{ $setting['key'] }}" @disabled(! $canManage)>
                                            </label>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>

            {{-- History --}}
            <x-ui.card :title="__('manager_appearance.menu.history.title')" flush>
                @if ($history === [])
                    <x-ui.empty-state compact icon="history" :title="__('manager_appearance.menu.history.empty')" />
                @else
                    <ul class="row-list row-list--flush">
                        @foreach ($history as $version)
                            <li class="row-list__item" wire:key="version-{{ $version['uuid'] }}">
                                <span class="grow">
                                    <span class="cell-title">{{ __('manager_appearance.menu.history.version', ['version' => $version['version']]) }} · {{ $version['template'] }}</span>
                                    <span class="cell-sub">{{ $version['at'] }} · {{ $version['by'] }}</span>
                                </span>
                                @if ($canManage)
                                    <button class="button button--ghost button--sm" type="button" wire:click="rollback('{{ $version['uuid'] }}')"
                                            wire:confirm="{{ __('manager_appearance.menu.history.confirm', ['version' => $version['version']]) }}"
                                            data-confirm-title="{{ __('manager_appearance.menu.history.confirm_title') }}"
                                            wire:loading.attr="data-loading" wire:target="rollback('{{ $version['uuid'] }}')">
                                        <x-ui.icon name="undo" size="16" />{{ __('manager_appearance.menu.history.restore') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </aside>
    </div>

    @if ($confirmingPublish)
        <x-ui.modal :title="__('manager_appearance.menu.publish_confirm.title')" :description="__('manager_appearance.menu.publish_confirm.body')" icon="rocket" close="$set('confirmingPublish', false)" submit="publish">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="$set('confirmingPublish', false)">{{ __('manager_appearance.actions.cancel') }}</button>
                <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="publish">{{ __('manager_appearance.menu.publish_confirm.confirm') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @include('livewire.center.appearance.menu-preview-shell', [
        'event' => 'menu-preview',
        'src' => route('center.appearance.menu.preview'),
        'title' => __('manager_appearance.menu.preview_title'),
        'locales' => $previewLocales,
        'primary' => $primaryLocale,
    ])
</div>
