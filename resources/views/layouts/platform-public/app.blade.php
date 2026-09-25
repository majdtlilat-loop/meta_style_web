@php
    use App\View\Landing;

    $languageRegistry = app(\App\Kernel\Localization\LanguageRegistry::class);
    $cmsContent = isset($content) && is_array($content) ? $content : \App\Modules\LandingCms\Domain\LandingContent::defaults();
    $onHome = request()->routeIs('home') || ($preview ?? false);
    // On other public pages (registration), a section anchor points back home.
    $link = fn (array $item): string => ($href = Landing::href($item)) !== '' && str_starts_with($href, '#') && ! $onHome ? route('home').$href : $href;
    $header = $cmsContent['header'] ?? [];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $languageRegistry->direction(app()->getLocale()) }}"{!! \App\View\ShellPreferences::htmlAttributes() !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ Landing::text(data_get($cmsContent, 'seo.title', []), $title ?? config('app.name')) }}</title>
    @stack('head')
    <script>document.documentElement.dataset.theme=localStorage.getItem('metastyle-theme')||((matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light')</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('platform.partials.brand-head')
</head>
<body class="corporate-body">
    <a class="skip-link" href="#main">{{ __('platform_public.skip_to_content') }}</a>
    <header class="corporate-header" data-style="{{ $header['style'] ?? 'solid' }}" @if($header['sticky'] ?? true) data-sticky @endif data-logo-variant="{{ $header['logo_variant'] ?? 'auto' }}"
            x-data="{ open: false, scrolled: window.scrollY > 8 }" x-on:scroll.window.passive="scrolled = window.scrollY > 8" x-on:keydown.escape.window="open = false" :class="{ 'is-open': open, 'is-scrolled': scrolled }">
        <div class="corporate-header__inner">
            <x-brand.logo :href="route('home')" />
            <button class="icon-button corporate-header__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="corporate-nav" aria-label="{{ __('platform_public.menu') }}">
                <x-ui.icon name="menu" x-show="! open" /><x-ui.icon name="close" x-show="open" x-cloak />
            </button>
            <nav class="corporate-nav" id="corporate-nav" aria-label="{{ __('platform_public.main_navigation') }}" x-on:click="if ($event.target.closest('a')) open = false">
                <div class="corporate-nav__links">
                    @foreach($cmsContent['navigation'] ?? [] as $item)
                        @continue(! ($item['enabled'] ?? false))
                        <a class="{{ ($item['style'] ?? 'link') === 'link' ? 'corporate-nav__link' : Landing::ctaClass($item).' button--sm' }}" href="{{ $link($item) }}" @if($item['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($item['label'] ?? []) }}</a>
                    @endforeach
                </div>
                <div class="corporate-nav__tools">
                    @if($header['show_language_switcher'] ?? true)<x-navigation.language-switcher />@endif
                    <x-navigation.theme-toggle />
                    @foreach(['secondary_cta', 'primary_cta'] as $ctaKey)
                        @php $cta = $header[$ctaKey] ?? []; @endphp
                        @if(Landing::showsCta($cta))<a class="{{ Landing::ctaClass($cta) }} button--sm" href="{{ $link($cta) }}" @if($cta['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($cta['label'] ?? []) }}</a>@endif
                    @endforeach
                </div>
            </nav>
        </div>
    </header>
    <main id="main" class="{{ request()->routeIs('home') || ($preview ?? false) ? 'corporate-main' : 'section-wrap' }}">
        @isset($slot)
            {{ $slot }}
        @else
            @yield('content')
        @endisset
    </main>
    @livewireScripts
</body>
</html>
