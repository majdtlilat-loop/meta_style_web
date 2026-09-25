<x-ui.card :title="__('manager_site.panels.header')">
    <div class="stack">
        <div class="cms-grid-3">
            <x-ui.field :label="__('manager_site.header.logo')" for="site-header-logo" name="content.header.logo" :help="__('manager_site.header.logo_help')">
                <select id="site-header-logo" wire:model="content.header.logo">
                    @foreach($choices['logos'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.header.style')" for="site-header-style" name="content.header.style">
                <select id="site-header-style" wire:model="content.header.style">
                    @foreach($choices['header_styles'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.header.layout')" for="site-header-layout" name="content.header.layout">
                <select id="site-header-layout" wire:model="content.header.layout">
                    @foreach($choices['header_layouts'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        </div>
        <div class="sb-switches">
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.header.show_name"><span>{{ __('manager_site.header.show_name') }}</span></label>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.header.sticky"><span>{{ __('manager_site.header.sticky') }}</span></label>
            <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model="content.header.show_language_switch"><span>{{ __('manager_site.header.language_switch') }}</span></label>
        </div>
        @if(count($locales) < 2)
            <p class="field-help">{{ __('manager_site.header.single_language') }}</p>
        @endif
    </div>
</x-ui.card>

<x-ui.card :title="__('manager_site.header.cta_title')">
    <div class="cms-item-list">
        @include('livewire.center.appearance.site.partials.cta', ['path' => 'content.header.cta', 'cta' => $content['header']['cta'], 'title' => __('manager_site.header.cta'), 'domId' => 'site-header-cta'])
    </div>
</x-ui.card>
