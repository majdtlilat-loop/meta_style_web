@php($languageRegistry = app(\App\Kernel\Localization\LanguageRegistry::class))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $languageRegistry->direction(app()->getLocale()) }}"{!! \App\View\ShellPreferences::htmlAttributes() !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title ?? __('platform_auth.page_title') }} · Meta Style</title>
    <script>document.documentElement.dataset.theme=localStorage.getItem('metastyle-theme')||((matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light')</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('platform.partials.brand-head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('superadmin_ui.skip_to_content') }}</a>
    <div class="platform-auth">
        <aside class="auth-story" aria-label="{{ __('platform_auth.platform') }}">
            <x-brand.logo :context="__('ui.contexts.platform')" />
            <div class="auth-story__copy">
                <p class="eyebrow">{{ __('ui.auth_story.platform_eyebrow') }}</p>
                <h1>{{ __('platform_auth.story_title') }}</h1>
                <ul class="auth-story__points">
                    <li><x-ui.icon name="check-circle" />{{ __('ui.auth_story.platform_point_1') }}</li>
                    <li><x-ui.icon name="check-circle" />{{ __('ui.auth_story.platform_point_2') }}</li>
                    <li><x-ui.icon name="check-circle" />{{ __('ui.auth_story.platform_point_3') }}</li>
                </ul>
            </div>
            <span></span>
        </aside>
        <main class="auth-stage" id="main">
            <div class="auth-controls"><x-navigation.language-switcher /><x-navigation.theme-toggle /></div>
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
