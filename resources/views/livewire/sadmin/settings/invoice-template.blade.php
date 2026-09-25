@php
    use App\Modules\SaasBilling\Domain\InvoiceTemplate as Schema;
    $company = $template['company'] ?? [];
    $texts = $template['texts'] ?? [];
@endphp

<div class="stack">
    <x-ui.page-header :title="__('platform_settings.title')" />

    <x-ui.flash />
    <x-ui.flash key="notice-error" tone="danger" />

    <div class="settings-layout">
        <x-sadmin.settings-nav current="invoices" />

        <div class="settings-panel stack">
            <form class="stack" wire:submit="save">
                <x-ui.card :title="__('platform_settings.invoice.issuer')" :description="__('platform_settings.invoice.issuer_help')">
                    <div class="stack">
                        <x-ui.lang-tabs id="invoice-company" primary="en" live :values="['template.company.name' => $company['name'] ?? [], 'template.company.address' => $company['address'] ?? []]" :fields="[
                            ['name' => 'template.company.name', 'label' => __('platform_settings.invoice.fields.name'), 'max' => 120, 'required' => true],
                            ['name' => 'template.company.address', 'label' => __('platform_settings.invoice.fields.address'), 'type' => 'textarea', 'rows' => 2, 'max' => 300],
                        ]" />
                        <div class="form-grid">
                            <x-ui.field :label="__('platform_settings.invoice.fields.phone')" for="invoice-phone" name="template.company.phone">
                                <input id="invoice-phone" type="tel" dir="ltr" wire:model.live.debounce.500ms="template.company.phone" maxlength="48" autocomplete="off">
                            </x-ui.field>
                            <x-ui.field :label="__('platform_settings.invoice.fields.email')" for="invoice-email" name="template.company.email">
                                <input id="invoice-email" type="email" dir="ltr" wire:model.live.debounce.500ms="template.company.email" maxlength="190" autocomplete="off">
                            </x-ui.field>
                            <x-ui.field :label="__('platform_settings.invoice.fields.website')" for="invoice-website" name="template.company.website" :help="__('platform_settings.invoice.fields.website_help')">
                                <input id="invoice-website" type="url" dir="ltr" wire:model.live.debounce.500ms="template.company.website" maxlength="190" autocomplete="off" placeholder="https://">
                            </x-ui.field>
                            <x-ui.field :label="__('platform_settings.invoice.fields.tax_number')" for="invoice-tax" name="template.company.tax_number">
                                <input id="invoice-tax" type="text" dir="ltr" wire:model.live.debounce.500ms="template.company.tax_number" maxlength="64" autocomplete="off">
                            </x-ui.field>
                        </div>
                        <div class="notice" data-tone="info"><x-ui.icon name="info" /><p>{{ __('platform_settings.invoice.history_note') }}</p></div>
                    </div>
                </x-ui.card>

                <x-ui.card :title="__('platform_settings.invoice.texts')" :description="__('platform_settings.invoice.texts_help')">
                    <x-ui.lang-tabs id="invoice-texts" primary="en" live :values="['template.texts.header' => $texts['header'] ?? [], 'template.texts.footer' => $texts['footer'] ?? [], 'template.texts.payment_instructions' => $texts['payment_instructions'] ?? [], 'template.texts.notes' => $texts['notes'] ?? []]" :fields="[
                        ['name' => 'template.texts.header', 'label' => __('platform_settings.invoice.fields.header'), 'max' => 200],
                        ['name' => 'template.texts.footer', 'label' => __('platform_settings.invoice.fields.footer'), 'type' => 'textarea', 'rows' => 2, 'max' => 500],
                        ['name' => 'template.texts.payment_instructions', 'label' => __('platform_settings.invoice.fields.payment_instructions'), 'type' => 'textarea', 'rows' => 3, 'max' => 1000],
                        ['name' => 'template.texts.notes', 'label' => __('platform_settings.invoice.fields.notes'), 'type' => 'textarea', 'rows' => 2, 'max' => 500],
                    ]" />
                </x-ui.card>

                <x-ui.card :title="__('platform_settings.invoice.layout')" :description="__('platform_settings.invoice.layout_help')">
                    <div class="stack">
                        <div class="form-grid">
                            <x-ui.field :label="__('platform_settings.invoice.fields.layout')" for="invoice-layout" name="template.layout">
                                <select id="invoice-layout" wire:model.live="template.layout">
                                    @foreach(Schema::LAYOUTS as $option)<option value="{{ $option }}">{{ __('platform_settings.invoice.layouts.'.$option) }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('platform_settings.invoice.fields.paper')" for="invoice-paper" name="template.paper">
                                <select id="invoice-paper" wire:model.live="template.paper">
                                    @foreach(Schema::PAPERS as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('platform_settings.invoice.fields.logo_size')" for="invoice-logo-size" name="template.logo_size">
                                <select id="invoice-logo-size" wire:model.live="template.logo_size">
                                    @foreach(array_keys(Schema::LOGO_SIZES) as $option)<option value="{{ $option }}">{{ __('platform_settings.invoice.logo_sizes.'.$option) }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :label="__('platform_settings.invoice.fields.logo_align')" for="invoice-logo-align" name="template.logo_align">
                                <select id="invoice-logo-align" wire:model.live="template.logo_align">
                                    @foreach(Schema::LOGO_ALIGNS as $option)<option value="{{ $option }}">{{ __('platform_settings.invoice.logo_aligns.'.$option) }}</option>@endforeach
                                </select>
                            </x-ui.field>
                        </div>
                        <fieldset class="field">
                            <legend>{{ __('platform_settings.invoice.fields.accent') }}</legend>
                            <div class="swatches">
                                @foreach(Schema::ACCENTS as $name => $hex)
                                    <label class="swatch" wire:key="accent-{{ $name }}">
                                        <input type="radio" class="sr-only" name="invoice-accent" value="{{ $name }}" wire:model.live="template.accent">
                                        <span class="swatch__chip" style="background: {{ $hex }}"></span>
                                        <span>{{ __('platform_settings.invoice.accents.'.$name) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <fieldset class="field">
                            <legend>{{ __('platform_settings.invoice.fields.show') }}</legend>
                            <div class="check-list check-list--columns">
                                @foreach(Schema::TOGGLES as $toggle)
                                    <label class="choice" wire:key="toggle-{{ $toggle }}">
                                        <input type="checkbox" wire:model.live="template.show.{{ $toggle }}">
                                        <span>{{ __('platform_settings.invoice.toggles.'.$toggle) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <p class="field-help">{{ __('platform_settings.invoice.logo_from_branding') }}
                            @if(auth('platform')->user()?->hasPermission('platform.branding.manage'))
                                <a href="{{ route('superadmin.settings.branding') }}" wire:navigate>{{ __('platform_settings.tabs.branding') }}</a>
                            @endif
                        </p>
                    </div>
                </x-ui.card>

                @error('template')<div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $message }}</p></div>@enderror
                @if($problem)<div class="notice" data-tone="warning"><x-ui.icon name="alert-triangle" /><p>{{ $problem }}</p></div>@endif

                <div class="form-actions">
                    @if($dirty)<span class="field-help">{{ __('platform_settings.invoice.unsaved') }}</span>@endif
                    <button class="button" type="submit" wire:loading.attr="data-loading" wire:target="save">{{ __('platform_settings.invoice.save') }}</button>
                </div>
            </form>

            <section class="card card--flush" aria-labelledby="invoice-preview">
                <header class="card__header">
                    <div>
                        <h2 id="invoice-preview">{{ __('platform_settings.invoice.preview') }}</h2>
                        <p>{{ __('platform_settings.invoice.preview_help') }}</p>
                    </div>
                    <div class="segmented" role="group" aria-label="{{ __('platform_settings.invoice.preview_language') }}">
                        @foreach($languages as $code => $label)
                            <button type="button" wire:click="setPreviewLocale('{{ $code }}')" aria-pressed="{{ $previewLocale === $code ? 'true' : 'false' }}" lang="{{ $code }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </header>
                <div class="document-preview" wire:loading.class="is-refreshing">
                    <iframe title="{{ __('platform_settings.invoice.preview') }}" srcdoc="{{ $preview }}" sandbox="allow-same-origin" loading="lazy"></iframe>
                </div>
            </section>
        </div>
    </div>
</div>
