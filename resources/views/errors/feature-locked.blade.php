{{--
    403 for a plan feature the center does not own (App\View\Manager\FeatureLockedPage).
    Inside the Manager shell for a page load; bare for a Livewire overlay.
    It explains the refusal and unlocks nothing.
--}}
@if($standalone)
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}"{!! \App\View\ShellPreferences::htmlAttributes() !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $offer['name'] }} · Meta Style</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <main class="feature-locked-page feature-locked-page--standalone" id="main">
        <x-manager.feature-locked :offer="$offer">
            @if($back)
                <a class="button button--ghost" href="{{ $back }}"><x-ui.icon name="arrow-left" size="18" />{{ __('manager_shell.locked_page.back') }}</a>
            @endif
        </x-manager.feature-locked>
    </main>
</body>
</html>
@else
<x-layouts.app>
    <x-slot:title>{{ $offer['name'] }}</x-slot:title>
    <div class="feature-locked-page">
        <x-manager.feature-locked :offer="$offer">
            @if($back)
                <a class="button button--ghost" href="{{ $back }}" wire:navigate><x-ui.icon name="arrow-left" size="18" />{{ __('manager_shell.locked_page.back') }}</a>
            @endif
        </x-manager.feature-locked>
    </div>
</x-layouts.app>
@endif
