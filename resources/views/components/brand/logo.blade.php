@props(['context' => null, 'href' => null])
@php
    $hosts = app(\App\Kernel\Tenancy\PlatformHosts::class);
    $href ??= request()->getHost() === $hosts->superAdminHost() && auth('platform')->check() ? route(\App\Kernel\Platform\Identity\PlatformLanding::routeFor(auth('platform')->user())) : route('home');
    // Platform branding (Settings → Branding) applies to Meta Style's own
    // surfaces only. On a center's host the bundled mark stays, so nothing a
    // Super Admin uploads ever reaches a center's pages.
    $platformSurface = in_array(request()->getHost(), [$hosts->corporateHost(), $hosts->superAdminHost()], true);
    $branding = $platformSurface ? app(\App\Kernel\Platform\Branding\PlatformBranding::class) : null;
    $light = $branding?->logo('light') ?? ['url' => asset('brand/meta-style-mark-light.svg'), 'custom' => false];
    $dark = $branding?->logo('dark') ?? ['url' => asset('brand/meta-style-mark-dark.svg'), 'custom' => false];
    $custom = $light['custom'] || $dark['custom'];
    $name = $branding ? app(\App\Kernel\Platform\Settings\PlatformPreferences::class)->general()['platform_name'] : 'Meta Style';
    $showName = $branding?->showsName() ?? true;
@endphp
<a href="{{ $href }}" {{ $attributes->class(['brand-lockup', 'brand-lockup--custom' => $custom]) }} @unless($showName) aria-label="{{ $name }}" @endunless>
    <img class="brand-logo--light" src="{{ $light['url'] }}" alt="" @unless($custom) width="36" height="36" @endunless>
    <img class="brand-logo--dark" src="{{ $dark['url'] }}" alt="" @unless($custom) width="36" height="36" @endunless>
    @if($showName || $context)
        <span>
            @if($showName)<span dir="auto">{{ $name }}</span>@endif
            @if ($context)<small class="brand-lockup__context">{{ $context }}</small>@endif
        </span>
    @endif
</a>
