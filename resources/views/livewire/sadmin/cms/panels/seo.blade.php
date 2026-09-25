@php
    $seo = $content['seo'];
    $locale = app()->getLocale();
    $pick = fn (array $value) => trim((string) ($value[$locale] ?? '')) !== '' ? $value[$locale] : ($value['en'] ?? '');
    $ogUrl = $seo['og_image'] ? \Illuminate\Support\Facades\Storage::disk('public')->url($seo['og_image']) : null;
@endphp
<x-ui.card :title="__('sadmin_cms.panels.seo')">
    <div class="stack">
        <x-ui.lang-tabs id="seo-text" primary="en" :values="['content.seo.title' => $seo['title'], 'content.seo.description' => $seo['description']]" :fields="[
            ['name' => 'content.seo.title', 'label' => __('sadmin_cms.fields.seo_title'), 'max' => 70, 'required' => true, 'counter' => true, 'recommended' => 60],
            ['name' => 'content.seo.description', 'label' => __('sadmin_cms.fields.seo_description'), 'type' => 'textarea', 'rows' => 3, 'max' => 170, 'required' => true, 'counter' => true, 'recommended' => 155],
        ]" />
        <div class="cms-grid-2">
            <div class="field">
                <label for="seo-canonical">{{ __('sadmin_cms.fields.canonical') }}</label>
                <input id="seo-canonical" dir="ltr" wire:model="content.seo.canonical" placeholder="https://">
            </div>
            <div class="stack stack--sm">
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.seo.index"><span>{{ __('sadmin_cms.fields.index') }}</span></label>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.seo.follow"><span>{{ __('sadmin_cms.fields.follow') }}</span></label>
            </div>
        </div>
        <div class="seo-preview" aria-hidden="true">
            <span class="seo-preview__url" dir="ltr">{{ route('home') }}</span>
            <strong class="seo-preview__title">{{ $pick($seo['title']) }}</strong>
            <span class="seo-preview__description">{{ $pick($seo['description']) }}</span>
            @unless($seo['index'])<span class="badge">noindex</span>@endunless
        </div>
    </div>
</x-ui.card>

<x-ui.card :title="__('sadmin_cms.seo_ui.social')">
    <div class="stack">
        <x-ui.lang-tabs id="seo-og" primary="en" :values="['content.seo.og_title' => $seo['og_title'], 'content.seo.og_description' => $seo['og_description'], 'content.seo.og_image_alt' => $seo['og_image_alt']]" :fields="[
            ['name' => 'content.seo.og_title', 'label' => __('sadmin_cms.fields.og_title'), 'max' => 95, 'counter' => true],
            ['name' => 'content.seo.og_description', 'label' => __('sadmin_cms.fields.og_description'), 'type' => 'textarea', 'rows' => 2, 'max' => 220, 'counter' => true],
            ['name' => 'content.seo.og_image_alt', 'label' => __('sadmin_cms.fields.og_image_alt'), 'max' => 190],
        ]" />
        <div class="cms-grid-2">
            @include('livewire.sadmin.cms.partials.media-slot', ['target' => 'seo.og_image', 'path' => $seo['og_image'], 'kind' => 'image', 'label' => __('sadmin_cms.fields.og_image'), 'poster' => null])
            <div class="stack stack--sm">
                <div class="field">
                    <label for="seo-twitter">{{ __('sadmin_cms.fields.twitter_card') }}</label>
                    <select id="seo-twitter" wire:model="content.seo.twitter_card">
                        <option value="summary_large_image">{{ __('sadmin_cms.options.card_large') }}</option>
                        <option value="summary">{{ __('sadmin_cms.options.card_small') }}</option>
                    </select>
                </div>
                <div class="social-card" aria-hidden="true">
                    <div class="social-card__image">@if($ogUrl)<img src="{{ $ogUrl }}" alt="">@else<x-ui.icon name="image" size="28" />@endif</div>
                    <div class="social-card__body">
                        <span class="muted" dir="ltr">{{ parse_url(route('home'), PHP_URL_HOST) }}</span>
                        <strong>{{ $pick($seo['og_title']) ?: $pick($seo['title']) }}</strong>
                        <span>{{ $pick($seo['og_description']) ?: $pick($seo['description']) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-ui.card>
