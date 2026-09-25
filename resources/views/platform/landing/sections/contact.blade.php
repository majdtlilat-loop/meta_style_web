@php
    use App\View\Landing;

    // Contact details are kept once, in the footer, and shown here too.
    $footer = $content['footer'] ?? [];
    $email = (string) ($footer['email'] ?? '');
    $phone = (string) ($footer['phone'] ?? '');
    $address = Landing::text($footer['address'] ?? []);
    $hasCta = Landing::showsCta($section['cta'] ?? []);
@endphp
{{-- A contact section with no way to make contact is not shown. --}}
@if($email !== '' || $phone !== '' || $address !== '' || $hasCta)
<x-landing.section :section="$section" :id="$id">
    <div class="landing-contact">
        <div>
            @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title'])
            @if($hasCta)
                <p class="landing-actions"><a class="{{ Landing::ctaClass($section['cta']) }}" href="{{ Landing::href($section['cta']) }}" @if($section['cta']['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($section['cta']['label']) }}</a></p>
            @endif
        </div>
        @if($email !== '' || $phone !== '' || $address !== '')
            <ul class="landing-contact__cards" role="list">
                @if($email !== '')
                    <li><x-ui.icon name="mail" :size="20" /><span><small>{{ __('platform_landing.contact.email') }}</small><a class="ltr" href="mailto:{{ $email }}">{{ $email }}</a></span></li>
                @endif
                @if($phone !== '')
                    <li><x-ui.icon name="phone" :size="20" /><span><small>{{ __('platform_landing.contact.phone') }}</small><a class="ltr" dir="ltr" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></span></li>
                @endif
                @if($address !== '')
                    <li><x-ui.icon name="map-pin" :size="20" /><span><small>{{ __('platform_landing.contact.address') }}</small>{{ $address }}</span></li>
                @endif
            </ul>
        @endif
    </div>
</x-landing.section>
@endif
