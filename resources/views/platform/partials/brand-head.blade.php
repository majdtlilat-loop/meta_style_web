{{--
    Platform-owned pages only (corporate site, Super Admin, platform sign-in):
    the favicon and the platform theme from Settings → Branding. The theme is
    generated from validated #rrggbb values and angles only — never stored CSS —
    and is empty while the Rose Gold Luxe defaults are in use.
--}}
@php
    $platformBranding = app(\App\Kernel\Platform\Branding\PlatformBranding::class);
    $platformFavicon = $platformBranding->favicon();
    $platformThemeCss = $platformBranding->themeCss();
@endphp
<link rel="icon" href="{{ $platformFavicon['url'] }}" type="{{ $platformFavicon['type'] }}">
@if($platformThemeCss !== '')
    <style id="platform-theme">{!! $platformThemeCss !!}</style>
@endif
