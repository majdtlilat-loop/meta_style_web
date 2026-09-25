@php
    use App\View\Landing;

    $layout = $hero['layout'] ?? 'split';
    $backgroundType = $hero['background_type'] ?? 'gradient';
    $backgroundImage = Landing::media($hero['background_image'] ?? null);
    $backgroundVideo = Landing::media($hero['background_video'] ?? null);
    $poster = Landing::media($hero['poster_image'] ?? null) ?? $backgroundImage;
    // A media background with nothing uploaded falls back to the brand gradient.
    if (($backgroundType === 'image' && ! $backgroundImage) || ($backgroundType === 'video' && ! $backgroundVideo)) {
        $backgroundType = 'gradient';
    }
    $hasMedia = ($hero['image'] ?? '') !== '' || ($hero['video'] ?? '') !== '';
    $eyebrow = Landing::text($hero['eyebrow'] ?? []);
    $subtitle = Landing::text($hero['subtitle'] ?? []);
    $body = Landing::text($hero['body'] ?? []);
@endphp
@if($hero['enabled'] ?? true)
    <section class="landing-hero" data-layout="{{ $layout }}" data-align="{{ $hero['alignment'] ?? 'start' }}" data-background="{{ $backgroundType }}"
             data-color="{{ $hero['background_color'] ?? 'cream' }}" data-gradient="{{ $hero['background_gradient'] ?? 'rose_cream' }}" data-overlay="{{ $hero['overlay'] ?? 'soft' }}"
             aria-labelledby="landing-hero-title"
             @if($backgroundType === 'image') style="--landing-hero-image: url('{{ $backgroundImage }}')" @endif>
        @if($backgroundType === 'video')
            <video class="landing-hero__video" src="{{ $backgroundVideo }}" @if($poster) poster="{{ $poster }}" @endif muted loop playsinline preload="metadata" aria-hidden="true"
                   x-data x-init="if (! matchMedia('(prefers-reduced-motion: reduce)').matches) { $el.autoplay = true; $el.play().catch(() => {}) }"></video>
        @endif
        <div class="landing-container landing-hero__inner">
            <div class="landing-hero__copy">
                @if($eyebrow !== '')<p class="landing-eyebrow">{{ $eyebrow }}</p>@endif
                <h1 class="landing-hero__title" id="landing-hero-title">{{ Landing::text($hero['title'] ?? [], __('platform_landing.hero.title')) }}</h1>
                @if($subtitle !== '')<p class="landing-hero__subtitle">{{ $subtitle }}</p>@endif
                @if($body !== '')<p class="landing-hero__body">{{ $body }}</p>@endif
                @php $ctas = array_filter([$hero['primary_cta'] ?? [], $hero['secondary_cta'] ?? []], fn (array $cta): bool => Landing::showsCta($cta)); @endphp
                @if($ctas !== [])
                    <div class="landing-hero__actions">
                        @foreach($ctas as $cta)
                            <a class="{{ Landing::ctaClass($cta) }} button--lg" href="{{ Landing::href($cta) }}" @if($cta['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($cta['label']) }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
            @if($layout !== 'cover')
                @if($hasMedia)
                    @include('platform.landing.partials.media', ['image' => $hero['image'] ?? '', 'video' => $hero['video'] ?? '', 'poster' => $hero['poster_image'] ?? '', 'alt' => $hero['media_alt'] ?? [], 'class' => 'landing-hero__media'])
                @elseif($layout === 'split')
                    {{-- No picture uploaded: a quiet illustration of what the workspace covers. --}}
                    <div class="landing-hero__visual" aria-hidden="true">
                        <div class="hero-visual">
                            <img class="hero-visual__mark brand-logo--light" src="{{ asset('brand/meta-style-mark-light.svg') }}" alt="" width="72" height="72">
                            <img class="hero-visual__mark brand-logo--dark" src="{{ asset('brand/meta-style-mark-dark.svg') }}" alt="" width="72" height="72">
                            @foreach(['calendar' => 'bookings', 'queue' => 'queue', 'pos' => 'till', 'loyalty' => 'loyalty', 'reports' => 'reports', 'conversations' => 'conversations'] as $icon => $label)
                                <span class="hero-visual__chip" style="--chip-index: {{ $loop->index }}"><x-ui.icon :name="$icon" :size="18" />{{ __('platform_landing.hero_visual.'.$label) }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </section>
@endif
