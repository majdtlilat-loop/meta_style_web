{{--
    Manager → Appearance → Cart page. The cart has no backend yet, so this
    styles an honest empty state — heading, copy, button, colours, layout —
    and the checkout notice. Nothing here can invent a cart line.
--}}
<div class="stack appearance-page">
    <x-ui.page-header :title="__('manager_appearance.cart.title')">
        <x-slot:actions>
            <button class="button button--secondary" type="button" wire:click="preview" wire:loading.attr="data-loading" wire:target="preview">
                <x-ui.icon name="eye" size="16" />{{ __('manager_appearance.actions.preview') }}
            </button>
            <a class="button button--ghost" href="{{ $publicUrl }}" target="_blank" rel="noopener"><x-ui.icon name="external" size="16" />{{ __('manager_appearance.actions.open_live') }}</a>
            @if ($canManage)
                <button class="button" type="button" wire:click="save" wire:loading.attr="data-loading" wire:target="save"><x-ui.icon name="save" size="16" />{{ __('manager_appearance.actions.save') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.notice :message="$notice" :tone="$noticeTone" dismiss="$set('notice', '')" />

    <div class="notice" data-tone="info" role="note"><x-ui.icon name="info" /><p>{{ __('manager_appearance.cart.phase_note') }}</p></div>

    @unless ($canManage)
        <div class="notice" data-tone="info" role="status"><x-ui.icon name="lock" /><p>{{ __('manager_appearance.read_only') }}</p></div>
    @endunless

    <div class="appearance-layout">
        <div class="appearance-main stack">
            <x-ui.card :title="__('manager_appearance.colours.title')">
                @include('livewire.center.appearance.menu-controls.colours', ['disabled' => ! $canManage, 'idPrefix' => 'cart-colour'])
                @include('livewire.center.appearance.menu-controls.switch', ['key' => 'show_logo', 'label' => __('manager_appearance.cart.show_logo'), 'help' => null, 'disabled' => ! $canManage])
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.cart.look.title')">
                <div class="form-grid">
                    @foreach (['layout', 'summary', 'background', 'cta_style'] as $choice)
                        @include('livewire.center.appearance.menu-controls.choice', [
                            'key' => $choice,
                            'label' => __('manager_appearance.cart.choices.'.$choice.'.label'),
                            'options' => $choices[$choice] ?? [],
                            'current' => (string) ($values[$choice] ?? ''),
                            'disabled' => ! $canManage,
                            'help' => null,
                        ])
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.texts.title')">
                <x-slot:actions><span class="info-tip" role="img" tabindex="0" title="{{ __('manager_appearance.texts.description') }}" aria-label="{{ __('manager_appearance.texts.description') }}"><x-ui.icon name="info" size="16" /></span></x-slot:actions>
                <div @if (! $canManage) x-data x-readonly-fields @endif>
                    <x-ui.lang-tabs id="cart-texts" :fields="$textFields" :values="$textValues" :locales="$contentLocales" :primary="$primaryLocale" live />
                </div>
            </x-ui.card>
        </div>

        <aside class="appearance-aside stack">
            <x-ui.card :title="__('manager_appearance.sketch.title')">
                <div class="page-sketch page-sketch--cart {{ implode(' ', $sketch['classes']) }}" style="@foreach ($sketch['vars'] as $property => $value){{ $property }}: {{ $value }}; @endforeach" aria-hidden="true">
                    <div class="page-sketch__header page-sketch__header--plain">
                        @if ($sketch['logo'])<img src="{{ $sketch['logo'] }}" alt="">@endif
                        <strong>{{ $sketch['heading'] }}</strong>
                        @if ($sketch['intro'])<small>{{ $sketch['intro'] }}</small>@endif
                    </div>
                    <div class="page-sketch__empty">
                        <strong>{{ $sketch['empty_title'] }}</strong>
                        <small>{{ $sketch['empty_body'] }}</small>
                        <span class="page-sketch__cta">{{ $sketch['cta_label'] }}</span>
                    </div>
                    @if ($sketch['summary'] !== 'hidden')
                        <div class="page-sketch__summary"><strong>{{ __('menu_public.cart.summary') }}</strong><small>{{ __('menu_public.cart.summary_empty') }}</small></div>
                    @endif
                </div>
            </x-ui.card>
            @if ($canManage)
                <x-ui.card>
                    <button class="button button--ghost button--sm" type="button" wire:click="restoreDefaults"
                            wire:confirm="{{ __('manager_appearance.restore_defaults_confirm') }}" data-confirm-title="{{ __('manager_appearance.restore_defaults') }}">
                        <x-ui.icon name="reset" size="16" />{{ __('manager_appearance.restore_defaults') }}
                    </button>
                </x-ui.card>
            @endif
        </aside>
    </div>

    @include('livewire.center.appearance.menu-preview-shell', [
        'event' => 'cart-preview',
        'src' => route('center.appearance.cart.preview'),
        'title' => __('manager_appearance.cart.preview_title'),
        'locales' => $previewLocales,
        'primary' => $primaryLocale,
    ])
</div>
