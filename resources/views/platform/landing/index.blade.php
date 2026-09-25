@extends('layouts.platform-public.app')
@php
    use App\View\Landing;

    $seo = $content['seo'] ?? [];
    $seoTitle = Landing::text($seo['title'] ?? [], 'Meta Style');
    $seoDescription = Landing::text($seo['description'] ?? [], __('platform_landing.hero.body'));
    $robots = (($seo['index'] ?? true) ? 'index' : 'noindex').', '.(($seo['follow'] ?? true) ? 'follow' : 'nofollow');
    $ogImage = Landing::media($seo['og_image'] ?? null);
    $languages = app(\App\Kernel\Localization\LanguageRegistry::class);
@endphp
@push('head')
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="robots" content="{{ ($preview ?? false) ? 'noindex, nofollow' : $robots }}">
    @if(($seo['canonical'] ?? '') !== '')<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Meta Style">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
    <meta property="og:title" content="{{ Landing::text($seo['og_title'] ?? [], $seoTitle) }}">
    <meta property="og:description" content="{{ Landing::text($seo['og_description'] ?? [], $seoDescription) }}">
    @if($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:alt" content="{{ Landing::text($seo['og_image_alt'] ?? [], 'Meta Style') }}">
    @endif
    <meta name="twitter:card" content="{{ $seo['twitter_card'] ?? 'summary_large_image' }}">
    <meta name="twitter:title" content="{{ Landing::text($seo['og_title'] ?? [], $seoTitle) }}">
    <meta name="twitter:description" content="{{ Landing::text($seo['og_description'] ?? [], $seoDescription) }}">
    @if($ogImage)<meta name="twitter:image" content="{{ $ogImage }}">@endif
    @unless($preview ?? false)
        @foreach($languages->supported() as $alternate)
            <link rel="alternate" hreflang="{{ $alternate === 'ckb' ? 'ckb' : $alternate }}" href="{{ route('home', ['locale' => $alternate]) }}">
        @endforeach
    @endunless
@endpush
@section('content')
@if($preview ?? false)
    <div class="preview-banner" role="status"><strong>{{ __('sadmin_cms.preview_mode') }}</strong><span>{{ __('sadmin_cms.preview_mode_help') }}</span></div>
@endif

@include('platform.landing.sections.hero', ['hero' => $content['hero']])

@foreach($content['section_order'] ?? [] as $id)
    @php $section = $content['sections'][$id] ?? null; @endphp
    @continue(! is_array($section) || ! ($section['enabled'] ?? true))
    @includeIf('platform.landing.sections.'.$section['type'], ['section' => $section, 'id' => $id, 'content' => $content, 'pricing' => $pricing])
@endforeach

@include('platform.landing.sections.footer', ['footer' => $content['footer']])
@endsection
