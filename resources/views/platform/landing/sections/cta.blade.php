@php use App\View\Landing; @endphp
<x-landing.section :section="$section" :id="$id" class="landing-section--band">
    <div class="landing-band">
        @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title'])
        @if(Landing::showsCta($section['cta'] ?? []))
            <a class="{{ Landing::ctaClass($section['cta']) }} button--lg" href="{{ Landing::href($section['cta']) }}" @if($section['cta']['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($section['cta']['label']) }}</a>
        @endif
    </div>
</x-landing.section>
