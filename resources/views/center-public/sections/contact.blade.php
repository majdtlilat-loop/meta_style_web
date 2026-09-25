{{-- One branch's published contact details, as presenter arrays ({label, href}). --}}
<div class="cs-contact" data-layout="{{ $section['layout'] }}">
    @include('center-public.partials.heading', ['section' => $section])
    <ul class="cs-grid cs-contact__cards" data-columns="{{ $section['layout'] === 'split' ? 2 : 4 }}">
        @if($data['address'] !== '')
            <li class="cs-card cs-contact__card"><span class="cs-icon" aria-hidden="true"><x-ui.icon name="map-pin" size="20" /></span><div class="cs-card__body"><strong>{{ __('center_site.contact.address') }}</strong><span>{{ $data['address'] }}</span>@if($data['map_href'])<a class="cs-card__link" href="{{ $data['map_href'] }}" target="_blank" rel="noopener noreferrer">{{ __('center_site.contact.directions') }}<x-ui.icon name="external" size="14" /></a>@endif</div></li>
        @endif
        @if($data['phone'])
            <li class="cs-card cs-contact__card"><span class="cs-icon" aria-hidden="true"><x-ui.icon name="phone" size="20" /></span><div class="cs-card__body"><strong>{{ __('center_site.contact.phone') }}</strong><a href="{{ $data['phone']['href'] }}" dir="ltr">{{ $data['phone']['label'] }}</a></div></li>
        @endif
        @if($data['whatsapp'])
            <li class="cs-card cs-contact__card"><span class="cs-icon" aria-hidden="true"><x-ui.icon name="message" size="20" /></span><div class="cs-card__body"><strong>{{ __('center_site.contact.whatsapp') }}</strong><a href="{{ $data['whatsapp']['href'] }}" target="_blank" rel="noopener" dir="ltr">{{ $data['whatsapp']['label'] }}</a></div></li>
        @endif
        @if($data['email'])
            <li class="cs-card cs-contact__card"><span class="cs-icon" aria-hidden="true"><x-ui.icon name="mail" size="20" /></span><div class="cs-card__body"><strong>{{ __('center_site.contact.email') }}</strong><a href="{{ $data['email']['href'] }}" dir="ltr">{{ $data['email']['label'] }}</a></div></li>
        @endif
        @if($data['address'] === '' && $data['map_href'])
            <li class="cs-card cs-contact__card"><span class="cs-icon" aria-hidden="true"><x-ui.icon name="map-pin" size="20" /></span><div class="cs-card__body"><strong>{{ $data['name'] }}</strong><a class="cs-card__link" href="{{ $data['map_href'] }}" target="_blank" rel="noopener noreferrer">{{ __('center_site.contact.directions') }}<x-ui.icon name="external" size="14" /></a></div></li>
        @endif
    </ul>
</div>
