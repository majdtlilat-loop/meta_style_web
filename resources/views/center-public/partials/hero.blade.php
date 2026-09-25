{{-- The hero. Layouts: split (text + media), centered, cover (media fills the backdrop), minimal. --}}
<section class="cs-hero" data-layout="{{ $hero['layout'] }}" data-align="{{ $hero['alignment'] }}" data-height="{{ $hero['height'] }}" data-bg="{{ $hero['background']['type'] }}"
    @if($hero['background']['type'] === 'color') data-bg-color="{{ $hero['background']['color'] }}" @endif
    @if($hero['background']['type'] === 'gradient') data-bg-gradient="{{ $hero['background']['gradient'] }}" @endif
    @if($hero['inverse']) data-inverse @endif
    @class(['cs-dark' => $hero['background']['type'] === 'color' && $hero['background']['color'] === 'dark'])>
    @include('center-public.partials.background', ['background' => $hero['background']])
    @if($hero['cover'])
        <div class="cs-backdrop" aria-hidden="true">
            @if($hero['video'])
                <video class="cs-backdrop__media" autoplay muted loop playsinline preload="metadata" @if($hero['video']['poster']) poster="{{ $hero['video']['poster'] }}" @endif><source src="{{ $hero['video']['url'] }}"></video>
            @else
                <img class="cs-backdrop__media" src="{{ $hero['image']['url'] }}" alt="">
            @endif
            <span class="cs-backdrop__overlay" data-overlay="{{ $hero['background']['overlay'] === 'none' ? 'soft' : $hero['background']['overlay'] }}"></span>
        </div>
    @endif
    <div class="cs-container cs-hero__inner">
        <div class="cs-hero__copy">
            @if($hero['eyebrow'] !== '')<p class="cs-eyebrow">{{ $hero['eyebrow'] }}</p>@endif
            <h1 class="cs-hero__title">{{ $hero['title'] }}</h1>
            @if($hero['subtitle'] !== '')<p class="cs-hero__subtitle">{{ $hero['subtitle'] }}</p>@endif
            @if($hero['body'] !== '')<p class="cs-hero__body">{{ $hero['body'] }}</p>@endif
            @if($hero['ctas'] !== [])
                <div class="cs-actions">
                    @foreach($hero['ctas'] as $cta)
                        @include('center-public.partials.cta', ['cta' => $cta])
                    @endforeach
                </div>
            @endif
        </div>
        @if($hero['layout'] === 'split' && ($hero['video'] || $hero['image']))
            <div class="cs-hero__media">
                @if($hero['video'])
                    <video autoplay muted loop playsinline preload="metadata" @if($hero['video']['poster']) poster="{{ $hero['video']['poster'] }}" @endif @if($hero['image']) aria-label="{{ $hero['image']['alt'] }}" @endif><source src="{{ $hero['video']['url'] }}"></video>
                @else
                    <img src="{{ $hero['image']['url'] }}" alt="{{ $hero['image']['alt'] }}" fetchpriority="high">
                @endif
            </div>
        @endif
    </div>
</section>
