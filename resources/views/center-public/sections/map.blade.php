{{-- A branch map. The frame's address is built from validated coordinates and the configured provider only. --}}
<div class="cs-map" data-layout="{{ $section['layout'] }}">
    <div class="cs-map__copy">
        @include('center-public.partials.heading', ['section' => $section])
        <p class="cs-with-icon"><x-ui.icon name="map-pin" size="18" /><span><strong>{{ $data['name'] }}</strong>@if($data['address'] !== '')<br>{{ $data['address'] }}@endif</span></p>
        @if($data['link'])<a class="cs-button cs-button--outline" href="{{ $data['link'] }}" target="_blank" rel="noopener noreferrer"><x-ui.icon name="external" size="16" />{{ __('center_site.contact.open_map') }}</a>@endif
    </div>
    @if($data['embed'])
        <div class="cs-map__frame">
            <iframe src="{{ $data['embed'] }}" title="{{ __('center_site.contact.map_of', ['name' => $data['name']]) }}" loading="lazy" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
        </div>
    @endif
</div>
