{{-- A booking call to action. --}}
<div class="cs-banner" data-layout="{{ $section['layout'] }}" data-media="{{ $section['media']['image'] || $section['media']['video'] ? $section['media']['position'] : 'none' }}">
    <div class="cs-banner__copy">
        @include('center-public.partials.heading', ['section' => $section])
        @if($section['cta'])<div class="cs-actions" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
    </div>
    @include('center-public.partials.media', ['media' => $section['media']])
</div>
