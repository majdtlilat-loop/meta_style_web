<div class="stack sb-brand">
    <x-ui.page-header :title="__('manager_site.brand.title')">
        <x-slot:meta>
            @if($dirty)
                <x-ui.status value="pending" :label="__('manager_site.brand.unsaved')" :dot="false" />
            @elseif($isDefault)
                <x-ui.status value="draft" :label="__('manager_site.brand.default_theme')" :dot="false" />
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <a class="button button--ghost" href="{{ route('center.appearance.site') }}" wire:navigate><x-ui.icon name="layout" size="16" />{{ __('manager_site.brand.open_builder') }}</a>
            @if($canManage)
                <button class="button" type="button" wire:click="save" wire:loading.attr="data-loading" wire:target="save" @disabled(! $valid)><x-ui.icon name="save" size="16" />{{ __('manager_site.brand.save') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />
    @unless($canManage)
        <x-ui.notice tone="info" :message="__('manager_site.read_only')" />
    @endunless

    <div class="sb-brand__layout">
        <div class="stack sb-brand__form">
            {{-- ── Logos ─────────────────────────────────────────────────── --}}
            <x-ui.card :title="__('manager_site.brand.logos.title')">
                <div class="brand-assets">
                    @foreach(['light' => 'logoLight', 'dark' => 'logoDark'] as $variant => $property)
                        <div class="brand-asset" wire:key="brand-logo-{{ $variant }}">
                            <div class="brand-asset__stage sb-asset-stage" data-surface="{{ $variant }}">
                                @if($assets['logo_'.$variant])
                                    <img src="{{ $assets['logo_'.$variant] }}" alt="{{ __('manager_site.brand.logos.'.$variant) }}">
                                @else
                                    <span class="sb-asset-stage__name">{{ $centerName }}</span>
                                @endif
                            </div>
                            <div class="brand-asset__meta">
                                <strong>{{ __('manager_site.brand.logos.'.$variant) }}</strong>
                                <span class="cell-sub">{{ $assets['logo_'.$variant] ? __('manager_site.brand.custom') : __('manager_site.brand.logos.none_'.$variant) }}</span>
                                @if($canManage)
                                    <div class="cluster">
                                        <label class="button button--secondary button--sm" for="brand-upload-{{ $variant }}"><x-ui.icon name="upload" size="16" />{{ $assets['logo_'.$variant] ? __('manager_site.media.replace') : __('manager_site.media.upload') }}</label>
                                        <input class="sr-only" id="brand-upload-{{ $variant }}" type="file" accept="image/png,image/jpeg,image/webp" wire:model="{{ $property }}">
                                        @if($assets['logo_'.$variant])
                                            <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askRemove('logo_{{ $variant }}')">{{ __('manager_site.media.remove') }}</button>
                                        @endif
                                    </div>
                                @endif
                                <div wire:loading wire:target="{{ $property }}" class="field-help"><span class="spinner" aria-hidden="true"></span> {{ __('manager_site.media.uploading') }}</div>
                                @error($property)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="field-help">{{ __('manager_site.brand.logos.rules', ['max' => $limits['logo_kb']]) }}</p>
            </x-ui.card>

            {{-- ── Favicon ───────────────────────────────────────────────── --}}
            <x-ui.card :title="__('manager_site.brand.favicon.title')">
                <div class="brand-asset">
                    <div class="brand-asset__stage brand-asset__stage--favicon">
                        @if($assets['favicon'])
                            <img src="{{ $assets['favicon'] }}" alt="" width="32" height="32">
                            <img src="{{ $assets['favicon'] }}" alt="" width="16" height="16">
                            <span class="brand-tab"><img src="{{ $assets['favicon'] }}" alt="" width="16" height="16"><span>{{ $centerName }}</span></span>
                        @else
                            <span class="brand-tab"><x-ui.icon name="globe" size="16" /><span>{{ $centerName }}</span></span>
                        @endif
                    </div>
                    <div class="brand-asset__meta">
                        <strong>{{ __('manager_site.brand.favicon.title') }}</strong>
                        <span class="cell-sub">{{ $assets['favicon'] ? __('manager_site.brand.custom') : __('manager_site.brand.favicon.none') }}</span>
                        @if($canManage)
                            <div class="cluster">
                                <label class="button button--secondary button--sm" for="brand-upload-favicon"><x-ui.icon name="upload" size="16" />{{ $assets['favicon'] ? __('manager_site.media.replace') : __('manager_site.media.upload') }}</label>
                                <input class="sr-only" id="brand-upload-favicon" type="file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico" wire:model="favicon">
                                @if($assets['favicon'])
                                    <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askRemove('favicon')">{{ __('manager_site.media.remove') }}</button>
                                @endif
                            </div>
                        @endif
                        <div wire:loading wire:target="favicon" class="field-help"><span class="spinner" aria-hidden="true"></span> {{ __('manager_site.media.uploading') }}</div>
                        @error('favicon')<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="field-help">{{ __('manager_site.brand.favicon.rules', ['max' => $limits['favicon_kb']]) }}</p>
            </x-ui.card>

            {{-- ── Colours ───────────────────────────────────────────────── --}}
            <section class="card card--flush" aria-labelledby="brand-colours">
                <header class="card__header">
                    <div>
                        <h2 id="brand-colours">{{ __('manager_site.brand.colors.title') }}</h2>
                    </div>
                    <div class="segmented" role="group" aria-label="{{ __('manager_site.brand.colors.palette') }}">
                        @foreach(['light' => 'sun', 'dark' => 'moon'] as $option => $icon)
                            <button type="button" wire:click="setMode('{{ $option }}')" aria-pressed="{{ $mode === $option ? 'true' : 'false' }}"><x-ui.icon :name="$icon" size="16" />{{ __('manager_site.brand.modes.'.$option) }}</button>
                        @endforeach
                    </div>
                </header>
                <div class="card__body stack">
                    <div class="color-grid">
                        @foreach($catalog['colors'] as $key)
                            <div class="color-field" wire:key="brand-color-{{ $mode }}-{{ $key }}">
                                <label for="brand-color-{{ $mode }}-{{ $key }}">{{ __('manager_site.brand.colors.keys.'.$key) }}</label>
                                <div class="color-field__control">
                                    <input type="color" aria-label="{{ __('manager_site.brand.colors.keys.'.$key) }}" wire:model.live.debounce.250ms="brand.{{ $mode }}.{{ $key }}" @disabled(! $canManage)>
                                    <input id="brand-color-{{ $mode }}-{{ $key }}" type="text" class="mono" dir="ltr" maxlength="7" spellcheck="false" autocomplete="off" wire:model.live.blur="brand.{{ $mode }}.{{ $key }}" @disabled(! $canManage)>
                                    @if($canManage && ($brand[$mode][$key] ?? '') !== $defaults[$mode][$key])
                                        <button class="icon-button" type="button" wire:click="defaultColor('{{ $key }}')" title="{{ __('manager_site.brand.colors.use_default') }}" aria-label="{{ __('manager_site.brand.colors.use_default') }}: {{ __('manager_site.brand.colors.keys.'.$key) }}"><x-ui.icon name="undo" size="16" /></button>
                                    @endif
                                </div>
                                @error('brand.'.$mode.'.'.$key)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    <x-ui.field :label="__('manager_site.brand.scheme.label')" for="brand-scheme" name="brand.scheme">
                        <select id="brand-scheme" wire:model.live="brand.scheme" @disabled(! $canManage)>
                            @foreach($catalog['schemes'] as $scheme)<option value="{{ $scheme }}">{{ __('manager_site.brand.scheme.'.$scheme) }}</option>@endforeach
                        </select>
                    </x-ui.field>
                </div>
            </section>

            {{-- ── Gradients ─────────────────────────────────────────────── --}}
            <x-ui.card :title="__('manager_site.brand.gradients.title')">
                <div class="gradient-list">
                    @foreach($catalog['gradients'] as $key)
                        <fieldset class="gradient-row" wire:key="brand-gradient-{{ $key }}">
                            <legend>{{ __('manager_site.brand.gradients.keys.'.$key) }}</legend>
                            <span class="gradient-row__swatch" style="background: {{ $swatches[$key] }}" aria-hidden="true"></span>
                            <div class="gradient-row__stops">
                                @foreach(['from', 'via', 'to'] as $stop)
                                    <div class="color-field">
                                        <label for="brand-gradient-{{ $key }}-{{ $stop }}">{{ __('manager_site.brand.gradients.'.$stop) }}</label>
                                        <div class="color-field__control">
                                            <input type="color" aria-label="{{ __('manager_site.brand.gradients.'.$stop) }}" wire:model.live.debounce.250ms="brand.gradients.{{ $key }}.{{ $stop }}" @disabled(! $canManage)>
                                            <input id="brand-gradient-{{ $key }}-{{ $stop }}" type="text" class="mono" dir="ltr" maxlength="7" spellcheck="false" autocomplete="off" wire:model.live.blur="brand.gradients.{{ $key }}.{{ $stop }}" @if($stop === 'via') placeholder="{{ __('manager_site.brand.gradients.none') }}" @endif @disabled(! $canManage)>
                                        </div>
                                    </div>
                                @endforeach
                                <x-ui.field :label="__('manager_site.brand.gradients.angle')" for="brand-gradient-{{ $key }}-angle" name="brand.gradients.{{ $key }}.angle">
                                    <select id="brand-gradient-{{ $key }}-angle" wire:model.live="brand.gradients.{{ $key }}.angle" @disabled(! $canManage)>
                                        @foreach($catalog['angles'] as $angle)<option value="{{ $angle }}">{{ __('manager_site.brand.gradients.angles.'.$angle) }}</option>@endforeach
                                    </select>
                                </x-ui.field>
                            </div>
                            @error('brand.gradients.'.$key)<p class="error" role="alert"><x-ui.icon name="alert-circle" size="14" />{{ $message }}</p>@enderror
                        </fieldset>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- ── Shape ─────────────────────────────────────────────────── --}}
            <x-ui.card :title="__('manager_site.brand.shape.title')">
                <div class="stack">
                    @foreach(['radius' => 'radii', 'button_style' => 'buttons', 'card_style' => 'cards'] as $field => $list)
                        <fieldset class="sb-choice-set">
                            <legend>{{ __('manager_site.brand.shape.'.$field) }}</legend>
                            <div class="sb-choice-set__options" role="radiogroup">
                                @foreach($catalog[$list] as $value)
                                    <label class="sb-choice" wire:key="brand-{{ $field }}-{{ $value }}">
                                        <input class="sr-only" type="radio" name="brand-{{ $field }}" value="{{ $value }}" wire:model.live="brand.{{ $field }}" @disabled(! $canManage)>
                                        <span class="sb-choice__sample" data-{{ str_replace('_', '-', $field) }}="{{ $value }}" aria-hidden="true"></span>
                                        <span>{{ __('manager_site.brand.shape.options.'.$value) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </x-ui.card>

            @if($canManage && ! $isDefault)
                <div class="sb-brand__reset">
                    <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askReset"><x-ui.icon name="reset" size="16" />{{ __('manager_site.brand.reset') }}</button>
                </div>
            @endif
        </div>

        {{-- ── Live preview ──────────────────────────────────────────────── --}}
        <aside class="sb-brand__aside" aria-labelledby="brand-preview-title">
            <section class="card card--flush">
                <header class="card__header"><div><h2 id="brand-preview-title">{{ __('manager_site.brand.preview.title') }}</h2></div></header>
                <div class="sb-mock" data-mode="{{ $mode }}" data-radius="{{ $brand['radius'] ?? 'rounded' }}" data-buttons="{{ $brand['button_style'] ?? 'solid' }}" data-cards="{{ $brand['card_style'] ?? 'bordered' }}" style="{{ $preview[$mode] }}">
                    <div class="sb-mock__bar">
                        @if($mode === 'dark' && $assets['logo_dark'])
                            <img src="{{ $assets['logo_dark'] }}" alt="">
                        @elseif($assets['logo_light'] ?? $assets['logo_dark'])
                            <img src="{{ $assets['logo_light'] ?? $assets['logo_dark'] }}" alt="">
                        @else
                            <strong>{{ $centerName }}</strong>
                        @endif
                        <span class="sb-mock__nav" aria-hidden="true"><span></span><span></span><span></span></span>
                        <span class="sb-mock__button">{{ __('manager_site.brand.preview.book') }}</span>
                    </div>
                    <div class="sb-mock__hero">
                        <small>{{ __('manager_site.brand.preview.eyebrow') }}</small>
                        <strong>{{ __('manager_site.brand.preview.hero') }}</strong>
                        <span class="sb-mock__button sb-mock__button--light">{{ __('manager_site.brand.preview.book') }}</span>
                    </div>
                    <div class="sb-mock__card">
                        <strong>{{ __('manager_site.brand.preview.card_title') }}</strong>
                        <p>{{ __('manager_site.brand.preview.body') }}</p>
                        <p class="sb-mock__muted">{{ __('manager_site.brand.preview.muted') }}</p>
                        <span class="sb-mock__link">{{ __('manager_site.brand.preview.link') }}</span>
                        <div class="sb-mock__actions">
                            <span class="sb-mock__button">{{ __('manager_site.brand.preview.primary') }}</span>
                            <span class="sb-mock__button sb-mock__button--secondary">{{ __('manager_site.brand.preview.secondary') }}</span>
                        </div>
                    </div>
                    <div class="sb-mock__swatches" aria-hidden="true">
                        <span data-swatch="brand"></span>
                        <span data-swatch="hero"></span>
                        <span data-swatch="accent-gradient"></span>
                        <span data-swatch="accent"></span>
                    </div>
                </div>
                @if($warnings !== [])
                    <div class="card__body">
                        <div class="notice" data-tone="warning">
                            <x-ui.icon name="alert-triangle" />
                            <div>
                                <p><strong>{{ __('manager_site.brand.contrast.title') }}</strong></p>
                                <ul class="contrast-list">
                                    @foreach($warnings as $warning)
                                        <li>{{ __('manager_site.brand.modes.'.$warning['mode']) }} · {{ __('manager_site.brand.contrast.pairs.'.$warning['pair']) }} — <span dir="ltr">{{ number_format($warning['ratio'], 2) }}:1</span> ({{ __('manager_site.brand.contrast.minimum', ['ratio' => number_format($warning['minimum'], 1)]) }})</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif
                @unless($valid)
                    <div class="card__body"><div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ __('manager_site.errors.color') }}</p></div></div>
                @endunless
            </section>
        </aside>
    </div>

    @if($confirm === 'reset')
        <x-ui.modal :title="__('manager_site.brand.reset_title')" :description="__('manager_site.brand.reset_body')" icon="palette" tone="danger" submit="confirmAction" close="cancel">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="cancel">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="confirmAction">{{ __('manager_site.brand.reset') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif(is_string($confirm) && str_starts_with($confirm, 'remove:'))
        <x-ui.modal :title="__('manager_site.brand.remove_title')" :description="__('manager_site.brand.remove_body')" icon="image" tone="danger" submit="confirmAction" close="cancel">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="cancel">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="confirmAction">{{ __('manager_site.media.remove') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
