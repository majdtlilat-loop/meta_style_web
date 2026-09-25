{{-- The public footer. Contact values arrive as presenter arrays ({label, href}). --}}
<footer class="cs-footer">
    <div class="cs-container cs-footer__grid">
        <div class="cs-footer__brand">
            @if($footer['show_logo'] && $footer['logo'])
                <img class="cs-footer__logo" src="{{ $footer['logo'] }}" alt="{{ $footer['name'] }}">
            @else
                <strong class="cs-footer__name">{{ $footer['name'] }}</strong>
            @endif
            @if($footer['description'] !== '')<p>{{ $footer['description'] }}</p>@endif
            @if($footer['cta'])
                <a class="cs-button cs-button--primary" href="{{ $footer['cta']['href'] }}">{{ $footer['cta']['label'] }}</a>
            @endif
        </div>

        @if($footer['address'] !== '' || $footer['phone'] || $footer['whatsapp'] || $footer['email'])
            <div class="cs-footer__col">
                <h2>{{ __('center_site.footer.contact') }}</h2>
                <ul class="cs-footer__list">
                    @if($footer['address'] !== '')<li><x-ui.icon name="map-pin" size="16" /><span>{{ $footer['address'] }}</span></li>@endif
                    @if($footer['phone'])<li><x-ui.icon name="phone" size="16" /><a href="{{ $footer['phone']['href'] }}" dir="ltr">{{ $footer['phone']['label'] }}</a></li>@endif
                    @if($footer['whatsapp'])<li><x-ui.icon name="message" size="16" /><a href="{{ $footer['whatsapp']['href'] }}" target="_blank" rel="noopener" dir="ltr">{{ $footer['whatsapp']['label'] }}</a></li>@endif
                    @if($footer['email'])<li><x-ui.icon name="mail" size="16" /><a href="{{ $footer['email']['href'] }}" dir="ltr">{{ $footer['email']['label'] }}</a></li>@endif
                </ul>
            </div>
        @endif

        @if($footer['hours'] !== [])
            <div class="cs-footer__col">
                <h2>{{ __('center_site.footer.hours') }}</h2>
                <dl class="cs-hours cs-hours--compact">
                    @foreach($footer['hours'] as $day)
                        <div><dt>{{ $day['day'] }}</dt><dd dir="ltr">{{ $day['value'] }}</dd></div>
                    @endforeach
                </dl>
            </div>
        @endif

        @if($footer['navigation'] !== [])
            <nav class="cs-footer__col" aria-label="{{ __('center_site.footer.links') }}">
                <h2>{{ __('center_site.footer.links') }}</h2>
                <ul class="cs-footer__list">
                    @foreach($footer['navigation'] as $link)
                        <li><a href="{{ $link['href'] }}" @if($link['new_tab']) target="_blank" rel="noopener{{ $link['external'] ? ' noreferrer' : '' }}" @endif>{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </div>

    <div class="cs-container cs-footer__bottom">
        <p>{{ $footer['copyright'] }}</p>
        @if($footer['legal'] !== [])
            <ul class="cs-footer__legal">
                @foreach($footer['legal'] as $link)
                    <li><a href="{{ $link['href'] }}" @if($link['new_tab']) target="_blank" rel="noopener{{ $link['external'] ? ' noreferrer' : '' }}" @endif>{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
        @if($footer['social'] !== [])
            <ul class="cs-social" aria-label="{{ __('center_site.footer.social') }}">
                @foreach($footer['social'] as $social)
                    <li><a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $social['label'] }}" title="{{ $social['label'] }}"><x-ui.icon :name="$social['icon']" size="18" /></a></li>
                @endforeach
            </ul>
        @endif
    </div>
</footer>
