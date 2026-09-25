@props(['section', 'id'])
@php
    use App\View\Landing;

    $anchor = (string) ($section['anchor'] ?? '');
    $background = (string) ($section['background'] ?? 'none');
    $image = $background === 'image' ? Landing::media($section['background_image'] ?? null) : null;
    // An image background without an image falls back to the plain page.
    $background = $background === 'image' && $image === null ? 'none' : $background;
    $titled = Landing::text($section['title'] ?? []) !== '';
@endphp
<section {{ $attributes->class(['landing-section', 'landing-section--'.($section['type'] ?? 'features')]) }}
         @if($anchor !== '') id="{{ $anchor }}" @endif
         data-background="{{ $background }}" data-layout="{{ $section['layout'] ?? 'grid' }}"
         @if($titled) aria-labelledby="landing-{{ $id }}-title" @endif
         @if($image) style="--landing-section-image: url('{{ $image }}')" @endif>
    <div class="landing-container">
        {{ $slot }}
    </div>
</section>
