{{-- The page header: the brand logo when the center has one, its name, the branch line and the language switch. --}}
<header class="hero">
    <div class="brand">
        @if (($config['show_logo'] ?? true) && $menu['logo'] !== null)
            <img class="logo" src="{{ $menu['logo'] }}" alt="{{ $center->name }}" height="56">
        @endif
        <div>
            <h1>{{ $center->name }}</h1>
            @if (($config['show_tagline'] ?? true) && $menu['branch'])
                <p class="tagline">{{ $menu['branch']->name?->get($locale) }}</p>
            @endif
        </div>
    </div>

    @if (count($locales) > 1 && $menu['presentation']->themeValue('language_switch', 'pills') === 'menu')
        <details class="langs">
            <summary aria-label="{{ __('menu_public.language') }}">{{ $languages->nativeName($locale) }} ▾</summary>
            <ul>
                @foreach ($locales as $code)
                    <li><a href="{{ request()->fullUrlWithQuery([$langParam ?? 'locale' => $code]) }}" lang="{{ $code }}" aria-current="{{ $code === $locale ? 'true' : 'false' }}">{{ $languages->nativeName($code) }}</a></li>
                @endforeach
            </ul>
        </details>
    @elseif (count($locales) > 1 && $menu['presentation']->themeValue('language_switch', 'pills') === 'pills')
        <nav class="langs" aria-label="{{ __('menu_public.language') }}">
            @foreach ($locales as $code)
                <a href="{{ request()->fullUrlWithQuery([$langParam ?? 'locale' => $code]) }}" lang="{{ $code }}" aria-current="{{ $code === $locale ? 'true' : 'false' }}">{{ $languages->nativeName($code) }}</a>
            @endforeach
        </nav>
    @endif
</header>
