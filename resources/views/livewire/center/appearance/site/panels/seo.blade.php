<x-ui.card :title="__('manager_site.panels.seo')">
    <div class="stack">
        <x-ui.lang-tabs id="site-seo" :locales="$locales" :primary="$primary" :values="['content.seo.title' => $content['seo']['title'], 'content.seo.description' => $content['seo']['description']]" :fields="[
            ['name' => 'content.seo.title', 'label' => __('manager_site.seo.title'), 'max' => $choices['limits']['seo_title'], 'required' => true, 'counter' => true, 'recommended' => 60],
            ['name' => 'content.seo.description', 'label' => __('manager_site.seo.description'), 'type' => 'textarea', 'rows' => 3, 'max' => $choices['limits']['seo_description'], 'required' => true, 'counter' => true, 'recommended' => 155],
        ]" />
        <div class="cms-grid-2">
            <x-ui.field :label="__('manager_site.seo.canonical')" for="site-seo-canonical" name="content.seo.canonical" :help="__('manager_site.seo.canonical_help')">
                <select id="site-seo-canonical" wire:model="content.seo.canonical">
                    @foreach($choices['canonical'] as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                </select>
            </x-ui.field>
            <div class="sb-switches sb-switches--stacked">
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.seo.index"><span>{{ __('manager_site.seo.index') }}</span></label>
                <label class="choice choice--switch"><input class="switch" type="checkbox" role="switch" wire:model.live="content.seo.follow"><span>{{ __('manager_site.seo.follow') }}</span></label>
            </div>
        </div>
        <div class="seo-preview" aria-hidden="true">
            <span class="seo-preview__url" dir="ltr">{{ $publicUrl }}</span>
            <strong class="seo-preview__title">{{ ($content['seo']['title'][app()->getLocale()] ?? '') ?: ($content['seo']['title'][$primary] ?? '') }}</strong>
            <span class="seo-preview__description">{{ ($content['seo']['description'][app()->getLocale()] ?? '') ?: ($content['seo']['description'][$primary] ?? '') }}</span>
            @unless($content['seo']['index'])<span class="badge">noindex</span>@endunless
        </div>
    </div>
</x-ui.card>

<x-ui.card :title="__('manager_site.seo.social')">
    <div class="stack">
        <x-ui.lang-tabs id="site-seo-og" :locales="$locales" :primary="$primary" :values="['content.seo.og_title' => $content['seo']['og_title'], 'content.seo.og_description' => $content['seo']['og_description'], 'content.seo.og_image_alt' => $content['seo']['og_image_alt']]" :fields="[
            ['name' => 'content.seo.og_title', 'label' => __('manager_site.seo.og_title'), 'max' => $choices['limits']['og_title'], 'counter' => true],
            ['name' => 'content.seo.og_description', 'label' => __('manager_site.seo.og_description'), 'type' => 'textarea', 'rows' => 2, 'max' => $choices['limits']['og_description'], 'counter' => true],
            ['name' => 'content.seo.og_image_alt', 'label' => __('manager_site.fields.alt'), 'max' => $choices['limits']['alt']],
        ]" />
        <div class="cms-grid-2">
            @include('livewire.center.appearance.site.partials.media', ['target' => 'seo.og_image', 'kind' => 'image', 'label' => __('manager_site.seo.og_image'), 'uuid' => $content['seo']['og_image'], 'poster' => ''])
            <div class="social-card" aria-hidden="true">
                <div class="social-card__image">@if(isset($media[$content['seo']['og_image']]))<img src="{{ $media[$content['seo']['og_image']]['url'] }}" alt="">@else<x-ui.icon name="image" size="28" />@endif</div>
                <div class="social-card__body">
                    <small dir="ltr">{{ parse_url($publicUrl, PHP_URL_HOST) }}</small>
                    <strong>{{ (($content['seo']['og_title'][app()->getLocale()] ?? '') ?: ($content['seo']['og_title'][$primary] ?? '')) ?: (($content['seo']['title'][app()->getLocale()] ?? '') ?: ($content['seo']['title'][$primary] ?? '')) }}</strong>
                    <p>{{ (($content['seo']['og_description'][app()->getLocale()] ?? '') ?: ($content['seo']['og_description'][$primary] ?? '')) ?: (($content['seo']['description'][app()->getLocale()] ?? '') ?: ($content['seo']['description'][$primary] ?? '')) }}</p>
                </div>
            </div>
        </div>
    </div>
</x-ui.card>
