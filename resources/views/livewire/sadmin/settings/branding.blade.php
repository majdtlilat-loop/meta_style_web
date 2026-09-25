@php
    use App\Kernel\Platform\Branding\PlatformTheme;
    $colors = PlatformTheme::COLORS;
@endphp

<div class="stack">
    <x-ui.page-header :title="__('platform_settings.title')" />

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />

    <div class="settings-layout">
        <x-sadmin.settings-nav current="branding" />

        <div class="settings-panel stack">
            {{-- ── Logo ─────────────────────────────────────────────── --}}
            <x-ui.card :title="__('platform_branding.logo.title')" :description="__('platform_branding.logo.help')">
                <div class="brand-assets">
                    @foreach(['light' => 'logoLight', 'dark' => 'logoDark'] as $variant => $property)
                        @php $slot = 'logo_'.$variant; @endphp
                        <div class="brand-asset" wire:key="asset-{{ $slot }}">
                            <div class="brand-asset__stage" data-surface="{{ $variant }}">
                                <img src="{{ $logos[$variant]['url'] }}" alt="{{ __('platform_branding.logo.'.$variant) }}">
                            </div>
                            <div class="brand-asset__meta">
                                <strong>{{ __('platform_branding.logo.'.$variant) }}</strong>
                                <span class="cell-sub">
                                    @if($custom[$slot]) {{ __('platform_branding.custom') }}
                                    @elseif($variant === 'dark' && $custom['logo_light']) {{ __('platform_branding.logo.dark_fallback') }}
                                    @else {{ __('platform_branding.default_asset') }} @endif
                                </span>
                                <div class="cluster">
                                    <label class="button button--secondary button--sm" for="upload-{{ $slot }}">
                                        <x-ui.icon name="upload" size="16" />{{ $custom[$slot] ? __('platform_branding.replace') : __('platform_branding.upload') }}
                                    </label>
                                    <input class="sr-only" id="upload-{{ $slot }}" type="file" accept="image/png,image/jpeg" wire:model="{{ $property }}">
                                    @if($custom[$slot])
                                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askRemove('{{ $slot }}')">{{ __('platform_branding.reset_asset') }}</button>
                                    @endif
                                </div>
                                <div wire:loading wire:target="{{ $property }}" class="field-help">{{ __('platform_branding.uploading') }}</div>
                                @error($property)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="field-help">{{ __('platform_branding.logo.rules', ['max' => \App\Kernel\Platform\Branding\PlatformBranding::LOGO_MAX_KB]) }}</p>
                <form class="setting-row" wire:submit="saveIdentity">
                    <label class="choice choice--switch">
                        <input type="checkbox" role="switch" wire:model="showName">
                        <span>{{ __('platform_branding.logo.show_name') }}<small class="field-help">{{ __('platform_branding.logo.show_name_help') }}</small></span>
                    </label>
                    <button class="button button--secondary button--sm" type="submit" wire:loading.attr="data-loading" wire:target="saveIdentity">{{ __('platform_settings.save') }}</button>
                </form>
            </x-ui.card>

            {{-- ── Favicon ──────────────────────────────────────────── --}}
            <x-ui.card :title="__('platform_branding.favicon.title')" :description="__('platform_branding.favicon.help')">
                <div class="brand-asset">
                    <div class="brand-asset__stage brand-asset__stage--favicon">
                        <img src="{{ $faviconUrl }}" alt="" width="32" height="32">
                        <img src="{{ $faviconUrl }}" alt="" width="16" height="16">
                        <span class="brand-tab"><img src="{{ $faviconUrl }}" alt="" width="16" height="16"><span>Meta Style</span></span>
                    </div>
                    <div class="brand-asset__meta">
                        <strong>{{ __('platform_branding.favicon.title') }}</strong>
                        <span class="cell-sub">{{ $custom['favicon'] ? __('platform_branding.custom') : __('platform_branding.default_asset') }}</span>
                        <div class="cluster">
                            <label class="button button--secondary button--sm" for="upload-favicon"><x-ui.icon name="upload" size="16" />{{ $custom['favicon'] ? __('platform_branding.replace') : __('platform_branding.upload') }}</label>
                            <input class="sr-only" id="upload-favicon" type="file" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico" wire:model="favicon">
                            @if($custom['favicon'])
                                <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askRemove('favicon')">{{ __('platform_branding.reset_asset') }}</button>
                            @endif
                        </div>
                        <div wire:loading wire:target="favicon" class="field-help">{{ __('platform_branding.uploading') }}</div>
                        @error('favicon')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="field-help">{{ __('platform_branding.favicon.rules', ['max' => \App\Kernel\Platform\Branding\PlatformBranding::FAVICON_MAX_KB]) }}</p>
            </x-ui.card>

            {{-- ── Colours ──────────────────────────────────────────── --}}
            <form class="card card--flush" wire:submit="saveTheme" aria-labelledby="brand-colours">
                <header class="card__header">
                    <div>
                        <h2 id="brand-colours">{{ __('platform_branding.colors.title') }}</h2>
                        <p>{{ __('platform_branding.colors.help') }}</p>
                    </div>
                    <div class="segmented" role="group" aria-label="{{ __('platform_branding.colors.mode') }}">
                        @foreach(PlatformTheme::MODES as $option)
                            <button type="button" wire:click="setMode('{{ $option }}')" aria-pressed="{{ $mode === $option ? 'true' : 'false' }}">
                                <x-ui.icon :name="$option === 'dark' ? 'moon' : 'sun'" size="16" />{{ __('platform_branding.modes.'.$option) }}
                            </button>
                        @endforeach
                    </div>
                </header>
                <div class="card__body stack">
                    <div class="color-grid">
                        @foreach($colors as $key)
                            <div class="color-field" wire:key="color-{{ $mode }}-{{ $key }}">
                                <label for="color-{{ $mode }}-{{ $key }}">{{ __('platform_branding.colors.keys.'.$key) }}</label>
                                <div class="color-field__control">
                                    <input type="color" aria-label="{{ __('platform_branding.colors.keys.'.$key) }}" wire:model.live.debounce.250ms="theme.{{ $mode }}.{{ $key }}">
                                    <input id="color-{{ $mode }}-{{ $key }}" type="text" class="mono" dir="ltr" maxlength="7" spellcheck="false" autocomplete="off" wire:model.live.blur="theme.{{ $mode }}.{{ $key }}">
                                    @if(($theme[$mode][$key] ?? '') !== PlatformTheme::DEFAULT_COLORS[$mode][$key])
                                        <button class="icon-button" type="button" wire:click="defaultColor('{{ $key }}')" title="{{ __('platform_branding.colors.use_default') }}" aria-label="{{ __('platform_branding.colors.use_default') }}: {{ __('platform_branding.colors.keys.'.$key) }}"><x-ui.icon name="undo" size="16" /></button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <h3>{{ __('platform_branding.gradients.title') }}</h3>
                    <p class="field-help">{{ __('platform_branding.gradients.help') }}</p>
                    <div class="gradient-list">
                        @foreach(PlatformTheme::GRADIENTS as $key)
                            @php $gradient = $theme['gradients'][$key]; @endphp
                            <fieldset class="gradient-row" wire:key="gradient-{{ $key }}">
                                <legend>{{ __('platform_branding.gradients.keys.'.$key) }}</legend>
                                <label class="choice choice--switch">
                                    <input type="checkbox" role="switch" wire:model.live="theme.gradients.{{ $key }}.enabled">
                                    <span>{{ __('platform_branding.gradients.enabled') }}</span>
                                </label>
                                <span class="gradient-row__swatch" style="background: {{ PlatformTheme::gradientCss(['enabled' => true, 'from' => \App\Kernel\Platform\Branding\Color::normalize($gradient['from']) ?? '#000000', 'via' => \App\Kernel\Platform\Branding\Color::normalize($gradient['via']) ?? '', 'to' => \App\Kernel\Platform\Branding\Color::normalize($gradient['to']) ?? '#000000', 'angle' => in_array((int) $gradient['angle'], PlatformTheme::ANGLES, true) ? (int) $gradient['angle'] : 135]) }}" aria-hidden="true"></span>
                                @if($gradient['enabled'])
                                    <div class="gradient-row__stops">
                                        @foreach(['from', 'via', 'to'] as $stop)
                                            <div class="color-field">
                                                <label for="gradient-{{ $key }}-{{ $stop }}">{{ __('platform_branding.gradients.'.$stop) }}</label>
                                                <div class="color-field__control">
                                                    <input type="color" aria-label="{{ __('platform_branding.gradients.'.$stop) }}" wire:model.live.debounce.250ms="theme.gradients.{{ $key }}.{{ $stop }}" @if($stop === 'via' && $gradient['via'] === '') value="#ffffff" @endif>
                                                    <input id="gradient-{{ $key }}-{{ $stop }}" type="text" class="mono" dir="ltr" maxlength="7" spellcheck="false" autocomplete="off" wire:model.live.blur="theme.gradients.{{ $key }}.{{ $stop }}" @if($stop === 'via') placeholder="{{ __('platform_branding.gradients.none') }}" @endif>
                                                </div>
                                            </div>
                                        @endforeach
                                        <x-ui.field :label="__('platform_branding.gradients.angle')" for="gradient-{{ $key }}-angle" name="theme.gradients.{{ $key }}.angle">
                                            <select id="gradient-{{ $key }}-angle" wire:model.live="theme.gradients.{{ $key }}.angle">
                                                @foreach(PlatformTheme::ANGLES as $angle)<option value="{{ $angle }}">{{ __('platform_branding.gradients.angles.'.$angle) }}</option>@endforeach
                                            </select>
                                        </x-ui.field>
                                    </div>
                                @else
                                    <p class="field-help">{{ __('platform_branding.gradients.off') }}</p>
                                @endif
                            </fieldset>
                        @endforeach
                    </div>
                    @error('theme')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @unless($valid)<div class="notice" data-tone="danger"><x-ui.icon name="alert-circle" /><p>{{ __('platform_branding.errors.color') }}</p></div>@endunless
                </div>
                <footer class="card__footer">
                    @unless($isDefault)
                        <button class="button button--ghost button--sm button--danger-text" type="button" wire:click="askReset">{{ __('platform_branding.reset') }}</button>
                    @endunless
                    @if($dirty)<span class="field-help">{{ __('platform_branding.unsaved') }}</span>@endif
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="saveTheme" @disabled(! $valid)>{{ __('platform_branding.save_theme') }}</button>
                </footer>
            </form>

            {{-- ── Live preview ─────────────────────────────────────── --}}
            <section class="card card--flush" aria-labelledby="brand-preview">
                <header class="card__header"><div><h2 id="brand-preview">{{ __('platform_branding.preview.title') }}</h2><p>{{ __('platform_branding.preview.help') }}</p></div></header>
                <div class="brand-previews">
                    @foreach(PlatformTheme::MODES as $option)
                        <div class="brand-preview" data-theme="{{ $option }}" style="{{ $preview[$option] }}" wire:key="preview-{{ $option }}">
                            <div class="brand-preview__bar">
                                <img src="{{ $logos[$option]['url'] }}" alt="">
                                <span>{{ __('platform_branding.modes.'.$option) }}</span>
                            </div>
                            <div class="brand-preview__hero"><strong>{{ __('platform_branding.preview.hero') }}</strong></div>
                            <div class="brand-preview__card">
                                <h3>{{ __('platform_branding.preview.card_title') }}</h3>
                                <p>{{ __('platform_branding.preview.body') }}</p>
                                <p class="muted">{{ __('platform_branding.preview.muted') }}</p>
                                <a href="#" onclick="return false">{{ __('platform_branding.preview.link') }}</a>
                                <div class="field">
                                    <label for="preview-input-{{ $option }}">{{ __('platform_branding.preview.input') }}</label>
                                    <input id="preview-input-{{ $option }}" type="text" value="{{ __('platform_branding.preview.input_value') }}" readonly tabindex="-1">
                                </div>
                                <div class="cluster">
                                    <button class="button button--sm" type="button" tabindex="-1">{{ __('platform_branding.preview.primary') }}</button>
                                    <button class="button button--secondary button--sm" type="button" tabindex="-1">{{ __('platform_branding.preview.secondary') }}</button>
                                </div>
                                <div class="cluster">
                                    @foreach(['success', 'warning', 'danger', 'info'] as $tone)
                                        <x-ui.status :tone="$tone" :label="__('platform_branding.colors.keys.'.$tone)" />
                                    @endforeach
                                </div>
                                <div class="brand-preview__gradients" aria-hidden="true">
                                    <span style="background: var(--gradient-primary)"></span>
                                    <span style="background: var(--gradient-accent)"></span>
                                    <span style="background: var(--color-secondary)"></span>
                                    <span style="background: var(--color-accent)"></span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($warnings !== [])
                    <div class="card__body">
                        <div class="notice" data-tone="warning">
                            <x-ui.icon name="alert-triangle" />
                            <div>
                                <p><strong>{{ __('platform_branding.contrast.title') }}</strong> {{ __('platform_branding.contrast.help') }}</p>
                                <ul class="contrast-list">
                                    @foreach($warnings as $warning)
                                        <li>{{ __('platform_branding.modes.'.$warning['mode']) }} · {{ __('platform_branding.contrast.pairs.'.$warning['pair']) }} — <span dir="ltr">{{ number_format($warning['ratio'], 2) }}:1</span> ({{ __('platform_branding.contrast.minimum', ['ratio' => number_format($warning['minimum'], 1)]) }})</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </div>

    @if($confirm === 'reset')
        <x-ui.modal :title="__('platform_branding.reset_title')" :description="__('platform_branding.reset_body')" icon="palette" tone="danger" submit="confirmAction">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="cancel">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="confirmAction">{{ __('platform_branding.reset') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @elseif(is_string($confirm) && str_starts_with($confirm, 'remove:'))
        <x-ui.modal :title="__('platform_branding.remove_title')" :description="__('platform_branding.remove_body')" icon="image" tone="danger" submit="confirmAction">
            <x-slot:footer>
                <button class="button button--secondary" type="button" wire:click="cancel">{{ __('ui.actions.cancel') }}</button>
                <button class="button button--danger" type="submit" wire:loading.attr="data-loading" wire:target="confirmAction">{{ __('platform_branding.reset_asset') }}</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
