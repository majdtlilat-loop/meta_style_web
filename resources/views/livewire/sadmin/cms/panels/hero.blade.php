@php
    use App\Modules\LandingCms\Domain\LandingContent;

    $hero = $content['hero'];
    $url = fn (?string $path) => $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
@endphp
<x-ui.card :title="__('sadmin_cms.panels.hero')">
    <x-slot:actions>
        <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.hero.enabled"><span>{{ __('sadmin_cms.fields.show_section') }}</span></label>
    </x-slot:actions>
    <div class="stack">
        <div class="cms-grid-3">
            <div class="field">
                <label for="hero-layout">{{ __('sadmin_cms.fields.layout') }}</label>
                <select id="hero-layout" wire:model.live="content.hero.layout">
                    @foreach(['split', 'centered', 'cover'] as $layout)<option value="{{ $layout }}">{{ __('sadmin_cms.options.hero_layout.'.$layout) }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label for="hero-alignment">{{ __('sadmin_cms.fields.alignment') }}</label>
                <select id="hero-alignment" wire:model="content.hero.alignment">
                    @foreach(['start', 'center'] as $alignment)<option value="{{ $alignment }}">{{ __('sadmin_cms.options.'.$alignment) }}</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label for="hero-overlay">{{ __('sadmin_cms.fields.overlay') }}</label>
                <select id="hero-overlay" wire:model="content.hero.overlay">
                    @foreach(['none', 'soft', 'strong'] as $overlay)<option value="{{ $overlay }}">{{ __('sadmin_cms.options.overlay.'.$overlay) }}</option>@endforeach
                </select>
            </div>
        </div>

        <x-ui.lang-tabs id="hero-text" primary="en" :values="[
            'content.hero.eyebrow' => $hero['eyebrow'], 'content.hero.title' => $hero['title'],
            'content.hero.subtitle' => $hero['subtitle'], 'content.hero.body' => $hero['body'],
        ]" :fields="[
            ['name' => 'content.hero.eyebrow', 'label' => __('sadmin_cms.fields.eyebrow'), 'max' => 120],
            ['name' => 'content.hero.title', 'label' => __('sadmin_cms.fields.title'), 'max' => 190, 'required' => true, 'counter' => true],
            ['name' => 'content.hero.subtitle', 'label' => __('sadmin_cms.fields.subtitle'), 'max' => 190],
            ['name' => 'content.hero.body', 'label' => __('sadmin_cms.fields.body'), 'type' => 'textarea', 'rows' => 3, 'max' => 600, 'required' => true, 'counter' => true],
        ]" />

        <h3 class="cms-subheading">{{ __('sadmin_cms.hero_actions') }}</h3>
        <div class="cms-item-list">
            @include('livewire.sadmin.cms.partials.cta', ['path' => 'content.hero.primary_cta', 'title' => __('sadmin_cms.fields.primary_cta'), 'cta' => $hero['primary_cta']])
            @include('livewire.sadmin.cms.partials.cta', ['path' => 'content.hero.secondary_cta', 'title' => __('sadmin_cms.fields.secondary_cta'), 'cta' => $hero['secondary_cta']])
        </div>
    </div>
</x-ui.card>

<x-ui.card :title="__('sadmin_cms.media_ui.title')">
    <div class="stack">
        <div class="cms-media-grid">
            @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'hero.image', 'path' => $hero['image'], 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.hero_image'), 'poster' => null])
            @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'hero.video', 'path' => $hero['video'], 'kind' => 'video', 'label' => __('sadmin_cms.media_ui.hero_video'), 'poster' => $url($hero['poster_image'])])
            @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'hero.poster_image', 'path' => $hero['poster_image'], 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.poster'), 'poster' => null])
        </div>
        <x-ui.lang-tabs id="hero-alt" primary="en" :values="['content.hero.media_alt' => $hero['media_alt']]" :fields="[
            ['name' => 'content.hero.media_alt', 'label' => __('sadmin_cms.fields.alt'), 'max' => 190],
        ]" />
    </div>
</x-ui.card>

<x-ui.card :title="__('sadmin_cms.fields.background')">
    <div class="stack">
        <div class="field">
            <label for="hero-bg-type">{{ __('sadmin_cms.fields.background_type') }}</label>
            <select id="hero-bg-type" wire:model.live="content.hero.background_type">
                @foreach(LandingContent::HERO_BACKGROUNDS as $type)<option value="{{ $type }}">{{ __('sadmin_cms.options.hero_bg.'.$type) }}</option>@endforeach
            </select>
        </div>
        @if($hero['background_type'] === 'color')
            <fieldset class="field">
                <legend>{{ __('sadmin_cms.fields.background_color') }}</legend>
                <div class="swatches">
                    @foreach(LandingContent::HERO_COLORS as $color)
                        <label class="swatch" data-swatch="{{ $color }}">
                            <input class="sr-only" type="radio" name="hero-color" value="{{ $color }}" wire:model.live="content.hero.background_color">
                            <span class="swatch__chip" aria-hidden="true"></span><span>{{ __('sadmin_cms.options.color.'.$color) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @elseif($hero['background_type'] === 'gradient')
            <fieldset class="field">
                <legend>{{ __('sadmin_cms.fields.background_gradient') }}</legend>
                <div class="swatches">
                    @foreach(LandingContent::HERO_GRADIENTS as $gradient)
                        <label class="swatch" data-swatch="{{ $gradient }}">
                            <input class="sr-only" type="radio" name="hero-gradient" value="{{ $gradient }}" wire:model.live="content.hero.background_gradient">
                            <span class="swatch__chip" aria-hidden="true"></span><span>{{ __('sadmin_cms.options.gradient.'.$gradient) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @elseif($hero['background_type'] === 'image')
            @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'hero.background_image', 'path' => $hero['background_image'], 'kind' => 'image', 'label' => __('sadmin_cms.media_ui.background_image'), 'poster' => null])
        @elseif($hero['background_type'] === 'video')
            @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'hero.background_video', 'path' => $hero['background_video'], 'kind' => 'video', 'label' => __('sadmin_cms.media_ui.background_video'), 'poster' => $url($hero['poster_image'])])
            <p class="field-help">{{ __('sadmin_cms.media_ui.poster_shared') }}</p>
        @endif
    </div>
</x-ui.card>
