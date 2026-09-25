{{--
    The Manager layout (and the center's sign-in pages).

    Everything around the page — navigation, trail, account menu, the
    subscription banner — arrives as `$shell` from App\View\Manager\ManagerShell
    (ManagerShellComposer). Nothing is decided here.
--}}
<!doctype html>
<html lang="{{ $shell['locale'] }}" dir="{{ $shell['dir'] }}"{!! \App\View\ShellPreferences::htmlAttributes(true) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $shell['trail_page'] ?? ($shell['signed_in'] ? __('ui.contexts.manager') : __('ui.auth_story.center_sign_in')) }}@if($shell['center_name']) · {{ $shell['center_name'] }}@endif · Meta Style</title>
    {{-- Decided before first paint, so neither the theme nor a collapsed sidebar ever flashes. --}}
    <script>document.documentElement.dataset.theme=localStorage.getItem('metastyle-theme')||((matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');document.documentElement.dataset.sidebar=localStorage.getItem('metastyle-sidebar')==='collapsed'?'collapsed':'expanded'</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<a class="skip-link" href="#main">{{ __('ui.skip_to_content') }}</a>
@if($shell['signed_in'])
    <div class="app-shell" data-app-shell data-nav-open="false">
        <button type="button" class="app-scrim" data-nav-close aria-label="{{ __('ui.shell.close_navigation') }}" tabindex="-1"></button>

        <x-shell.sidebar id="manager-navigation" :label="__('ui.manager_nav.label')" :context="$shell['center_name'] ?? __('ui.contexts.manager')" :groups="$shell['groups']" :home="$shell['home']" />

        <div class="app-workspace">
            <header class="app-topbar">
                <div class="app-topbar__context">
                    <button type="button" class="icon-button mobile-nav-toggle" data-nav-toggle aria-controls="manager-navigation" aria-expanded="false" aria-label="{{ __('ui.shell.open_navigation') }}"><x-ui.icon name="menu" /></button>
                    <div class="app-topbar__trail">
                        @if($shell['trail_group'])<span>{{ $shell['trail_group'] }}</span><x-ui.icon name="chevron-right" class="ui-icon--directional" />@endif
                        <strong>{{ $shell['trail_page'] ?? __('ui.contexts.manager') }}</strong>
                    </div>
                </div>
                <div class="topbar-actions">
                    {{-- The INTERFACE language: always EN / AR / KU. The center's content
                         languages are a separate setting (Settings → Languages). --}}
                    <x-navigation.language-switcher />
                    <x-navigation.theme-toggle />
                    @persist('manager-bell')<livewire:center.shell.notification-bell />@endpersist
                    <span class="topbar-divider" aria-hidden="true"></span>
                    <x-shell.account-menu :name="$shell['account']['name']" :initials="$shell['account']['initials']" :email="$shell['account']['contact_email']" :role="$shell['account']['role_label'] ?? $shell['center_name']" :logout="$shell['logout']" :links="$shell['account']['links']" />
                </div>
            </header>
            @if($shell['banner'])
                <x-shell.subscription-banner :banner="$shell['banner']" />
            @endif
            <main class="app-main" id="main" tabindex="-1">{{ $slot }}</main>
        </div>
    </div>
    <livewire:center.shell.upgrade-prompt />
    <x-shell.confirm-template />
@else
    <div class="platform-auth">
        <aside class="auth-story">
            <x-brand.logo :context="$shell['center_name'] ?? __('ui.contexts.center')" />
            <div class="auth-story__copy">
                <p class="eyebrow">{{ __('ui.auth_story.center_eyebrow') }}</p>
                <h1>{{ __('ui.auth_story.center_title') }}</h1>
                <ul class="auth-story__points">
                    <li><x-ui.icon name="check-circle" />{{ __('ui.auth_story.center_point_1') }}</li>
                    <li><x-ui.icon name="check-circle" />{{ __('ui.auth_story.center_point_2') }}</li>
                    <li><x-ui.icon name="check-circle" />{{ __('ui.auth_story.center_point_3') }}</li>
                </ul>
            </div>
            <span></span>
        </aside>
        <main class="auth-stage" id="main">
            <div class="auth-controls"><x-navigation.language-switcher /><x-navigation.theme-toggle /></div>
            <div class="auth-card">
                <x-brand.logo :context="$shell['center_name'] ?? __('ui.contexts.center')" class="auth-card__brand" />
                {{ $slot }}
            </div>
        </main>
    </div>
@endif
@livewireScripts
</body>
</html>
