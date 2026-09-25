{{-- One section's frame: anchor, layout, backdrop, heading; the type's own template inside. --}}
<section id="{{ $section['anchor'] !== '' ? $section['anchor'] : 's-'.$section['id'] }}"
    class="cs-section cs-section--{{ $section['type'] }}"
    data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}" data-align="{{ $section['alignment'] }}"
    data-visibility="{{ $section['visibility'] }}" data-bg="{{ $section['background']['type'] }}"
    @if($section['background']['type'] === 'color') data-bg-color="{{ $section['background']['color'] }}" @endif
    @if($section['background']['type'] === 'gradient') data-bg-gradient="{{ $section['background']['gradient'] }}" @endif
    @if($section['background']['inverse']) data-inverse @endif
    @class(['cs-dark' => $section['background']['type'] === 'color' && $section['background']['color'] === 'dark'])>
    @include('center-public.partials.background', ['background' => $section['background']])
    <div class="cs-container">
        @if($section['empty'])
            <div class="cs-preview-empty" role="note">
                <strong>{{ __('center_site.preview.empty_title', ['section' => $section['title'] !== '' ? $section['title'] : __('center_site.types.'.$section['type'])]) }}</strong>
                <span>{{ __('center_site.preview.empty_help') }}</span>
            </div>
        @else
            @include('center-public.sections.'.$section['template'], ['section' => $section, 'data' => $section['data']])
        @endif
    </div>
</section>
