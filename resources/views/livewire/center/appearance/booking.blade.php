{{--
    Manager → Appearance → Booking page. The frame around the existing public
    booking flow: colours, header, steps, button and customer copy. Every value
    is a validated choice, colour or plain text (SavePageAppearance).
--}}
<div class="stack appearance-page">
    <x-ui.page-header :title="__('manager_appearance.booking.title')">
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

    @unless ($canManage)
        <div class="notice" data-tone="info" role="status"><x-ui.icon name="lock" /><p>{{ __('manager_appearance.read_only') }}</p></div>
    @endunless

    <div class="appearance-layout">
        <div class="appearance-main stack">
            <x-ui.card :title="__('manager_appearance.colours.title')">
                @include('livewire.center.appearance.menu-controls.colours', ['disabled' => ! $canManage, 'idPrefix' => 'booking-colour'])
                @include('livewire.center.appearance.menu-controls.switch', ['key' => 'show_logo', 'label' => __('manager_appearance.booking.show_logo'), 'help' => null, 'disabled' => ! $canManage])
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.booking.look.title')">
                <div class="form-grid">
                    @foreach (['header_style', 'background', 'steps_style', 'cta_style'] as $choice)
                        @include('livewire.center.appearance.menu-controls.choice', [
                            'key' => $choice,
                            'label' => __('manager_appearance.booking.choices.'.$choice.'.label'),
                            'options' => $choices[$choice] ?? [],
                            'current' => (string) ($values[$choice] ?? ''),
                            'disabled' => ! $canManage,
                            'help' => null,
                        ])
                    @endforeach
                </div>
                @include('livewire.center.appearance.menu-controls.switch', ['key' => 'show_prices', 'label' => __('manager_appearance.booking.show_prices'), 'help' => null, 'disabled' => ! $canManage])
                @include('livewire.center.appearance.menu-controls.switch', ['key' => 'show_duration', 'label' => __('manager_appearance.booking.show_duration'), 'help' => null, 'disabled' => ! $canManage])
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.texts.title')">
                <x-slot:actions><span class="info-tip" role="img" tabindex="0" title="{{ __('manager_appearance.texts.description') }}" aria-label="{{ __('manager_appearance.texts.description') }}"><x-ui.icon name="info" size="16" /></span></x-slot:actions>
                <div @if (! $canManage) x-data x-readonly-fields @endif>
                    <x-ui.lang-tabs id="booking-texts" :fields="$textFields" :values="$textValues" :locales="$contentLocales" :primary="$primaryLocale" live />
                </div>
            </x-ui.card>

            <x-ui.card :title="__('manager_appearance.booking.policies.title')">
                @include('livewire.center.appearance.menu-controls.switch', ['key' => 'show_policies', 'label' => __('manager_appearance.booking.policies.show'), 'help' => null, 'disabled' => ! $canManage])
                @if ($policiesUrl)
                    <p class="field-help"><a href="{{ $policiesUrl }}" wire:navigate>{{ __('manager_appearance.booking.policies.edit') }}</a></p>
                @endif
            </x-ui.card>
        </div>

        <aside class="appearance-aside stack">
            <x-ui.card :title="__('manager_appearance.sketch.title')">
                <div class="page-sketch {{ implode(' ', $sketch['classes']) }}" style="@foreach ($sketch['vars'] as $property => $value){{ $property }}: {{ $value }}; @endforeach" aria-hidden="true">
                    <div class="page-sketch__header">
                        @if ($sketch['logo'])<img src="{{ $sketch['logo'] }}" alt="">@endif
                        <strong>{{ $sketch['title'] }}</strong>
                        @if ($sketch['intro'])<small>{{ $sketch['intro'] }}</small>@endif
                    </div>
                    <ol class="page-sketch__steps">
                        <li><span>1</span>{{ __('menu_public.booking.step_service') }}</li>
                        <li><span>2</span>{{ __('menu_public.booking.step_time') }}</li>
                        <li><span>3</span>{{ __('menu_public.booking.step_details') }}</li>
                    </ol>
                    <span class="page-sketch__cta">{{ $sketch['cta_label'] }}</span>
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
        'event' => 'booking-preview',
        'src' => route('center.appearance.booking.preview'),
        'title' => __('manager_appearance.booking.preview_title'),
        'locales' => $previewLocales,
        'primary' => $primaryLocale,
    ])
</div>
