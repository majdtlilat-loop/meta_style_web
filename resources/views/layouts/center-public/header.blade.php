{{-- The public header: brand, menu, language switch, call to action. Mobile menu is a native <details>. --}}
<header class="cs-header" data-style="{{ $header['style'] }}" data-layout="{{ $header['layout'] }}" @if($header['sticky']) data-sticky @endif>
    <div class="cs-container cs-header__bar">
        <a class="cs-brand" href="{{ $header['home'] }}" aria-label="{{ $brand['name'] }}">
            @if($header['logo'])
                <picture>
                    @if($header['logo_dark'])<source srcset="{{ $header['logo_dark'] }}" media="(prefers-color-scheme: dark)">@endif
                    <img class="cs-brand__logo" src="{{ $header['logo'] }}" alt="{{ $header['show_name'] ? '' : $brand['name'] }}">
                </picture>
            @endif
            @if($header['show_name'])<span class="cs-brand__name">{{ $brand['name'] }}</span>@endif
        </a>

        @if($header['nav'] !== [])
            <nav class="cs-nav" aria-label="{{ __('center_site.nav_label') }}">
                <ul>
                    @foreach($header['nav'] as $link)
                        <li><a href="{{ $link['href'] }}" @if($link['new_tab']) target="_blank" rel="noopener{{ $link['external'] ? ' noreferrer' : '' }}" @endif>{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <div class="cs-header__actions">
            @if($header['languages'] !== [])
                <nav class="cs-langs" aria-label="{{ __('center_site.languages') }}">
                    @foreach($header['languages'] as $language)
                        <a href="{{ $language['href'] }}" hreflang="{{ $language['code'] }}" lang="{{ $language['code'] }}" title="{{ $language['native'] }}" @if($language['active']) aria-current="true" @endif>{{ $language['short'] }}</a>
                    @endforeach
                </nav>
            @endif
            @if($header['cta'])
                <a class="cs-button cs-button--{{ $header['cta']['style'] }} cs-header__cta" href="{{ $header['cta']['href'] }}" @if($header['cta']['new_tab']) target="_blank" rel="noopener" @endif>{{ $header['cta']['label'] }}</a>
            @endif
            @if($header['nav'] !== [] || $header['languages'] !== [] || $header['cta'])
                <details class="cs-menu">
                    <summary class="cs-menu__toggle" aria-label="{{ __('center_site.menu') }}"><x-ui.icon name="menu" size="22" /></summary>
                    <div class="cs-menu__panel">
                        @if($header['nav'] !== [])
                            <ul class="cs-menu__links">
                                @foreach($header['nav'] as $link)
                                    <li><a href="{{ $link['href'] }}" @if($link['new_tab']) target="_blank" rel="noopener{{ $link['external'] ? ' noreferrer' : '' }}" @endif>{{ $link['label'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                        @if($header['languages'] !== [])
                            <div class="cs-menu__langs" role="group" aria-label="{{ __('center_site.languages') }}">
                                @foreach($header['languages'] as $language)
                                    <a href="{{ $language['href'] }}" hreflang="{{ $language['code'] }}" lang="{{ $language['code'] }}" @if($language['active']) aria-current="true" @endif>{{ $language['native'] }}</a>
                                @endforeach
                            </div>
                        @endif
                        @if($header['cta'])
                            <a class="cs-button cs-button--{{ $header['cta']['style'] }}" href="{{ $header['cta']['href'] }}" @if($header['cta']['new_tab']) target="_blank" rel="noopener" @endif>{{ $header['cta']['label'] }}</a>
                        @endif
                    </div>
                </details>
            @endif
        </div>
    </div>
</header>
