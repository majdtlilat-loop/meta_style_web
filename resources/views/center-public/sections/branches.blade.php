{{-- Public branches with address, hours and contact from the branch records. --}}
@include('center-public.partials.heading', ['section' => $section])
<ul class="cs-grid cs-branches" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}">
    @foreach($data['items'] as $branch)
        <li class="cs-card">
            <div class="cs-card__body">
                <h3>{{ $branch['name'] }}</h3>
                @if($branch['address'] !== '')<p class="cs-with-icon"><x-ui.icon name="map-pin" size="16" /><span>{{ $branch['address'] }}</span></p>@endif
                @if($branch['days'] !== [])
                    <dl class="cs-hours cs-hours--compact">
                        @foreach($branch['days'] as $day)
                            <div><dt>{{ $day['day'] }}</dt><dd dir="ltr">{{ $day['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                <div class="cs-links">
                    @if($branch['phone'])<a href="{{ $branch['phone']['href'] }}" dir="ltr"><x-ui.icon name="phone" size="16" />{{ $branch['phone']['label'] }}</a>@endif
                    @if($branch['whatsapp'])<a href="{{ $branch['whatsapp']['href'] }}" target="_blank" rel="noopener"><x-ui.icon name="message" size="16" />{{ __('center_site.contact.whatsapp') }}</a>@endif
                    @if($branch['map_href'])<a href="{{ $branch['map_href'] }}" target="_blank" rel="noopener noreferrer"><x-ui.icon name="map-pin" size="16" />{{ __('center_site.contact.directions') }}</a>@endif
                </div>
            </div>
        </li>
    @endforeach
</ul>
