@php
    use App\View\Landing;

    $imageUrl = Landing::media($image ?? null);
    $videoUrl = Landing::media($video ?? null);
    $posterUrl = Landing::media($poster ?? null) ?? $imageUrl;
    $altText = Landing::text($alt ?? []);
@endphp
@if($videoUrl)
    <figure class="landing-media {{ $class ?? '' }}">
        <video src="{{ $videoUrl }}" @if($posterUrl) poster="{{ $posterUrl }}" @endif muted loop playsinline preload="metadata"
               @if($altText !== '') aria-label="{{ $altText }}" @endif
               x-data x-init="if (! matchMedia('(prefers-reduced-motion: reduce)').matches) { $el.autoplay = true; $el.play().catch(() => {}) }"></video>
    </figure>
@elseif($imageUrl)
    <figure class="landing-media {{ $class ?? '' }}">
        <img src="{{ $imageUrl }}" alt="{{ $altText }}" loading="lazy" decoding="async">
    </figure>
@endif
