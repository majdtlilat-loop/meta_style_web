@php
    use App\View\Landing;

    $hasMedia = ($section['image'] ?? '') !== '' || ($section['video'] ?? '') !== '';
    $points = array_values(array_filter($section['items'] ?? [], fn (array $item): bool => ($item['enabled'] ?? true) && Landing::text($item['title'] ?? []) !== ''));
@endphp
<x-landing.section :section="$section" :id="$id">
    <div class="landing-split" @if($hasMedia) data-media="{{ $section['media_position'] ?? 'end' }}" @endif>
        <div class="landing-split__text">
            @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title'])
            @if($points !== [])
                <ul class="landing-points" role="list">
                    @foreach($points as $point)
                        <li><x-ui.icon :name="$point['icon'] ?? 'check'" :size="18" />
                            <span><strong>{{ Landing::text($point['title']) }}</strong>@if(($body = Landing::text($point['body'] ?? [])) !== '') <span>{{ $body }}</span>@endif</span></li>
                    @endforeach
                </ul>
            @endif
            @if(Landing::showsCta($section['cta'] ?? []))
                <p class="landing-actions"><a class="{{ Landing::ctaClass($section['cta']) }}" href="{{ Landing::href($section['cta']) }}" @if($section['cta']['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($section['cta']['label']) }}</a></p>
            @endif
        </div>
        @if($hasMedia)
            @include('platform.landing.partials.media', ['image' => $section['image'] ?? '', 'video' => $section['video'] ?? '', 'poster' => $section['poster'] ?? '', 'alt' => $section['image_alt'] ?? [], 'class' => 'landing-split__media'])
        @endif
    </div>
</x-landing.section>
