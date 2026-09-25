<x-ui.card :title="__('manager_site.panels.hero')">
    <x-slot:actions>
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.hero.enabled"><span>{{ __('manager_site.fields.show_section') }}</span></label>
    </x-slot:actions>
    <div class="stack">
        <div class="cms-grid-3">
            <x-ui.field :label="__('manager_site.fields.layout')" for="site-hero-layout" name="content.hero.layout">
                <select id="site-hero-layout" wire:model.live="content.hero.layout">
                    @foreach($choices['hero_layouts'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.fields.alignment')" for="site-hero-alignment" name="content.hero.alignment">
                <select id="site-hero-alignment" wire:model="content.hero.alignment">
                    @foreach($choices['alignments'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('manager_site.hero.height')" for="site-hero-height" name="content.hero.height">
                <select id="site-hero-height" wire:model="content.hero.height">
                    @foreach($choices['hero_heights'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
        </div>

        <x-ui.lang-tabs id="site-hero-text" :locales="$locales" :primary="$primary" :values="[
            'content.hero.eyebrow' => $content['hero']['eyebrow'], 'content.hero.title' => $content['hero']['title'],
            'content.hero.subtitle' => $content['hero']['subtitle'], 'content.hero.body' => $content['hero']['body'],
        ]" :fields="[
            ['name' => 'content.hero.eyebrow', 'label' => __('manager_site.fields.eyebrow'), 'max' => $choices['limits']['eyebrow']],
            ['name' => 'content.hero.title', 'label' => __('manager_site.fields.title'), 'max' => $choices['limits']['title'], 'required' => (bool) $content['hero']['enabled'], 'counter' => true],
            ['name' => 'content.hero.subtitle', 'label' => __('manager_site.fields.subtitle'), 'max' => $choices['limits']['subtitle']],
            ['name' => 'content.hero.body', 'label' => __('manager_site.hero.body'), 'type' => 'textarea', 'rows' => 3, 'max' => $choices['limits']['hero_body'], 'counter' => true],
        ]" />

        <h3 class="cms-subheading">{{ __('manager_site.hero.actions') }}</h3>
        <div class="cms-item-list">
            @include('livewire.center.appearance.site.partials.cta', ['path' => 'content.hero.primary_cta', 'cta' => $content['hero']['primary_cta'], 'title' => __('manager_site.hero.primary_cta'), 'domId' => 'site-hero-cta-1'])
            @include('livewire.center.appearance.site.partials.cta', ['path' => 'content.hero.secondary_cta', 'cta' => $content['hero']['secondary_cta'], 'title' => __('manager_site.hero.secondary_cta'), 'domId' => 'site-hero-cta-2'])
        </div>
    </div>
</x-ui.card>

<x-ui.card :title="__('manager_site.media.title')">
    <x-slot:actions>
        <span class="info-tip" role="img" tabindex="0" title="{{ __('manager_site.hero.media_help') }}" aria-label="{{ __('manager_site.hero.media_help') }}"><x-ui.icon name="info" size="16" /></span>
    </x-slot:actions>
    <div class="stack">
        <div class="cms-media-grid">
            @include('livewire.center.appearance.site.partials.media', ['target' => 'hero.image', 'kind' => 'image', 'label' => __('manager_site.hero.image'), 'uuid' => $content['hero']['image'], 'poster' => ''])
            @include('livewire.center.appearance.site.partials.media', ['target' => 'hero.video', 'kind' => 'video', 'label' => __('manager_site.hero.video'), 'uuid' => $content['hero']['video'], 'poster' => $content['hero']['poster']])
            @include('livewire.center.appearance.site.partials.media', ['target' => 'hero.poster', 'kind' => 'image', 'label' => __('manager_site.fields.poster'), 'uuid' => $content['hero']['poster'], 'poster' => ''])
        </div>
        <x-ui.lang-tabs id="site-hero-alt" :locales="$locales" :primary="$primary" :values="['content.hero.image_alt' => $content['hero']['image_alt']]" :fields="[
            ['name' => 'content.hero.image_alt', 'label' => __('manager_site.fields.alt'), 'max' => $choices['limits']['alt']],
        ]" />
    </div>
</x-ui.card>

@include('livewire.center.appearance.site.partials.background', ['path' => 'content.hero.background', 'target' => 'hero.background', 'background' => $content['hero']['background'], 'domId' => 'site-hero'])
