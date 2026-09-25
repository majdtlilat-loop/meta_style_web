{{--
    The center's public frame. Everything comes from one allow-listed array
    ($page, built by CenterSite\Application\PublicSitePage): brand tokens,
    header, footer, SEO. Pages that do not build one (cart, checkout) receive
    the published frame from CenterPublicShellComposer.
--}}
<!doctype html>
<html lang="{{ $page['locale'] }}" dir="{{ $page['direction'] }}" class="cs-root" data-scheme="{{ $page['theme']['scheme'] }}" data-radius="{{ $page['theme']['radius'] }}" data-buttons="{{ $page['theme']['button'] }}" data-cards="{{ $page['theme']['card'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · {{ $page['brand']['name'] }}@else{{ $page['seo']['title'] }}@endif</title>
    <meta name="description" content="{{ $page['seo']['description'] }}">
    <meta name="robots" content="{{ $page['seo']['robots'] }}">
    @if($page['seo']['canonical'])<link rel="canonical" href="{{ $page['seo']['canonical'] }}">@endif
    @foreach($page['seo']['alternates'] as $alternate)
        <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $page['seo']['og']['site_name'] }}">
    <meta property="og:locale" content="{{ $page['seo']['og']['locale'] }}">
    <meta property="og:title" content="{{ $page['seo']['og']['title'] }}">
    <meta property="og:description" content="{{ $page['seo']['og']['description'] }}">
    <meta property="og:url" content="{{ $page['seo']['og']['url'] }}">
    @if($page['seo']['og']['image'])
        <meta property="og:image" content="{{ $page['seo']['og']['image'] }}">
        <meta property="og:image:alt" content="{{ $page['seo']['og']['image_alt'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $page['seo']['og']['image'] }}">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="{{ $page['seo']['og']['title'] }}">
    <meta name="twitter:description" content="{{ $page['seo']['og']['description'] }}">
    @if($page['brand']['favicon'])<link rel="icon" href="{{ $page['brand']['favicon'] }}" type="{{ $page['brand']['favicon_type'] }}">@endif
    @if($page['theme']['scheme'] !== 'light')<meta name="color-scheme" content="{{ $page['theme']['scheme'] === 'dark' ? 'dark' : 'light dark' }}">@endif
    @vite('resources/css/center-public/app.css')
    <style>{{ $page['theme']['css'] }}</style>
    @stack('head')
</head>
<body class="cs-body">
    <a class="cs-skip" href="#main">{{ __('center_site.skip') }}</a>
    @if($page['preview'])
        <div class="cs-preview-banner" role="status"><strong>{{ __('center_site.preview.title') }}</strong><span>{{ __('center_site.preview.help') }}</span></div>
    @endif
    @include('layouts.center-public.header', ['header' => $page['header'], 'brand' => $page['brand']])
    <main id="main" class="cs-main" tabindex="-1">@yield('content')</main>
    @if($page['footer'])
        @include('layouts.center-public.footer', ['footer' => $page['footer']])
    @endif
</body>
</html>
