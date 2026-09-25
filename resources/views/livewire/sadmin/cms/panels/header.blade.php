<x-ui.card :title="__('sadmin_cms.panels.header')">
    <div class="stack">
        <div class="cms-grid-3">
            <div class="field">
                <label for="cms-logo-variant">{{ __('sadmin_cms.fields.logo_variant') }}</label>
                <select id="cms-logo-variant" wire:model="content.header.logo_variant">
                    @foreach(['auto', 'light', 'dark'] as $variant)
                        <option value="{{ $variant }}">{{ __('sadmin_cms.options.'.$variant) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="cms-header-style">{{ __('sadmin_cms.fields.header_style') }}</label>
                <select id="cms-header-style" wire:model="content.header.style">
                    @foreach(['solid', 'transparent', 'blur'] as $style)
                        <option value="{{ $style }}">{{ __('sadmin_cms.options.header.'.$style) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="stack stack--sm">
                <label class="choice choice--switch">
                    <input class="switch" type="checkbox" role="switch" wire:model="content.header.sticky">
                    <span>{{ __('sadmin_cms.fields.sticky') }}</span>
                </label>
                <label class="choice choice--switch">
                    <input class="switch" type="checkbox" role="switch" wire:model="content.header.show_language_switcher">
                    <span>{{ __('sadmin_cms.fields.language_switcher') }}</span>
                </label>
            </div>
        </div>
        <h3 class="cms-subheading">{{ __('sadmin_cms.header_actions') }}</h3>
        <div class="cms-item-list">
            @include('livewire.sadmin.cms.partials.cta', ['path' => 'content.header.primary_cta', 'title' => __('sadmin_cms.fields.primary_cta'), 'cta' => $content['header']['primary_cta']])
            @include('livewire.sadmin.cms.partials.cta', ['path' => 'content.header.secondary_cta', 'title' => __('sadmin_cms.fields.secondary_cta'), 'cta' => $content['header']['secondary_cta']])
        </div>
    </div>
</x-ui.card>
