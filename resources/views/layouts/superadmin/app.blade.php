@php
    $languageRegistry = app(\App\Kernel\Localization\LanguageRegistry::class);
    $platformUser = auth('platform')->user();
    $can = static fn (string $permission): bool => (bool) $platformUser?->hasPermission($permission);
    $item = static fn (string $route, string $active, string $icon, string $label): array => [
        'href' => Route::has($route) ? route($route) : '#',
        'icon' => $icon,
        'label' => $label,
        'active' => request()->routeIs($active),
        'route' => $route,
    ];

    // Grouped by what an operator is doing, not by table. Each link is shown
    // only with its permission; every route authorises again regardless.
    $openTickets = $can('platform.support.view')
        ? (int) \Illuminate\Support\Facades\DB::connection('control')->table('support_tickets')->where('status', 'open')->count()
        : 0;
    $groups = [
        ['label' => null, 'items' => array_values(array_filter([
            $can('platform.dashboard.view') ? $item('superadmin.dashboard', 'superadmin.dashboard', 'dashboard', __('sadmin_shell.nav.dashboard')) : null,
        ]))],
        ['label' => __('sadmin_shell.groups.centers'), 'items' => array_values(array_filter([
            $can('platform.center.view') ? $item('superadmin.centers.index', 'superadmin.centers.*', 'centers', __('sadmin_shell.nav.centers')) : null,
            $can('platform.center_user.view') ? $item('superadmin.center-users.index', 'superadmin.center-users.*', 'users', __('sadmin_shell.nav.center_users')) : null,
            $can('platform.subscription.manage') ? $item('superadmin.subscriptions.index', 'superadmin.subscriptions.*', 'subscriptions', __('sadmin_shell.nav.subscriptions')) : null,
            $can('platform.usage.manage') ? $item('superadmin.usage.index', 'superadmin.usage.*', 'usage', __('sadmin_shell.nav.usage')) : null,
            $can('platform.entitlement.manage') ? $item('superadmin.entitlements.index', 'superadmin.entitlements.*', 'entitlements', __('sadmin_shell.nav.entitlements')) : null,
        ]))],
        ['label' => __('sadmin_shell.groups.commercial'), 'items' => array_values(array_filter([
            $can('platform.plan.manage') ? $item('superadmin.plans.index', 'superadmin.plans.*', 'plans', __('sadmin_shell.nav.plans')) : null,
            $can('platform.billing.manage') ? $item('superadmin.billing.index', 'superadmin.billing.*', 'billing', __('sadmin_shell.nav.billing')) : null,
            $can('platform.settings.manage') ? $item('superadmin.currencies.index', 'superadmin.currencies.*', 'coins', __('sadmin_shell.nav.currencies')) : null,
        ]))],
        ['label' => __('sadmin_shell.groups.operations'), 'items' => array_values(array_filter([
            $can('platform.support.view') ? $item('superadmin.support.index', 'superadmin.support.*', 'support', __('sadmin_shell.nav.support')) + ['count' => $openTickets] : null,
            $platformUser ? $item('superadmin.alerts.index', 'superadmin.alerts.*', 'notifications', __('sadmin_shell.nav.notifications')) : null,
            $can('platform.announcement.send') ? $item('superadmin.announcements.index', 'superadmin.announcements.*', 'megaphone', __('sadmin_shell.nav.announcements')) : null,
            $can('platform.operations.view') ? $item('superadmin.operations.index', 'superadmin.operations.*', 'operations', __('sadmin_shell.nav.operations')) : null,
            $can('platform.audit.view') ? $item('superadmin.audit.index', 'superadmin.audit.*', 'audit', __('sadmin_shell.nav.audit')) : null,
        ]))],
        ['label' => __('sadmin_shell.groups.content'), 'items' => array_values(array_filter([
            $can('platform.cms.manage') ? $item('superadmin.cms.index', 'superadmin.cms.*', 'cms', __('sadmin_shell.nav.cms')) : null,
        ]))],
        ['label' => __('sadmin_shell.groups.platform'), 'items' => array_values(array_filter([
            $can('platform.user.manage') ? $item('superadmin.users.index', 'superadmin.users.*', 'users', __('sadmin_shell.nav.users')) : null,
            $can('platform.user.manage') ? $item('superadmin.roles.index', 'superadmin.roles.*', 'roles', __('sadmin_shell.nav.roles')) : null,
            // Settings is one entry; its first section this person may open.
            ($settingsRoute = collect(['platform.settings.manage' => 'superadmin.settings.index', 'platform.branding.manage' => 'superadmin.settings.branding', 'platform.ai.manage' => 'superadmin.settings.ai', 'platform.billing.manage' => 'superadmin.settings.invoices'])->first(fn ($route, $permission) => $can($permission)))
                ? $item($settingsRoute, 'superadmin.settings.*', 'settings', __('sadmin_shell.nav.settings')) : null,
        ]))],
    ];

    // The topbar trail: which group, which page.
    $trailGroup = null;
    $trailPage = null;
    foreach ($groups as $group) {
        foreach ($group['items'] as $candidate) {
            if ($candidate['active']) {
                $trailGroup = $group['label'];
                $trailPage = $candidate['label'];
            }
        }
    }
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $languageRegistry->direction(app()->getLocale()) }}"{!! \App\View\ShellPreferences::htmlAttributes(true) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title ?? $trailPage ?? __('superadmin_ui.title') }} · Meta Style</title>
    {{-- Decided before first paint, so neither the theme nor a collapsed sidebar ever flashes. --}}
    <script>document.documentElement.dataset.theme=localStorage.getItem('metastyle-theme')||((matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light');document.documentElement.dataset.sidebar=localStorage.getItem('metastyle-sidebar')==='collapsed'?'collapsed':'expanded'</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('platform.partials.brand-head')
</head>
<body>
<a class="skip-link" href="#main">{{ __('superadmin_ui.skip_to_content') }}</a>
<div class="app-shell" data-app-shell data-nav-open="false">
    <button type="button" class="app-scrim" data-nav-close aria-label="{{ __('ui.shell.close_navigation') }}" tabindex="-1"></button>

    <x-shell.sidebar id="sadmin-navigation" :label="__('superadmin_ui.navigation_label')" :context="__('ui.contexts.super_admin')" :groups="$groups" :home="route(\App\Kernel\Platform\Identity\PlatformLanding::routeFor($platformUser))" />

    <div class="app-workspace">
        <header class="app-topbar">
            <div class="app-topbar__context">
                <button type="button" class="icon-button mobile-nav-toggle" data-nav-toggle aria-controls="sadmin-navigation" aria-expanded="false" aria-label="{{ __('ui.shell.open_navigation') }}"><x-ui.icon name="menu" /></button>
                <div class="app-topbar__trail">
                    @if($trailGroup)<span>{{ $trailGroup }}</span><x-ui.icon name="chevron-right" class="ui-icon--directional" />@endif
                    <strong>{{ $trailPage ?? (request()->routeIs('superadmin.account') ? __('sadmin_shell.account.title') : __('superadmin_ui.topbar.operations')) }}</strong>
                </div>
            </div>
            <div class="topbar-actions">
                <x-navigation.language-switcher />
                <x-navigation.theme-toggle />
                @persist('notification-bell')<livewire:sadmin.shell.notification-bell />@endpersist
                <span class="topbar-divider" aria-hidden="true"></span>
                <x-shell.account-menu
                    :name="$platformUser?->name ?? ''"
                    :email="$platformUser?->email"
                    :role="__('ui.contexts.super_admin')"
                    :logout="route('superadmin.logout')"
                    :links="array_values(array_filter([
                        ['href' => route('superadmin.account'), 'icon' => 'user', 'label' => __('sadmin_shell.account.title')],
                        $can('platform.settings.manage') ? ['href' => route('superadmin.settings.index'), 'icon' => 'settings', 'label' => __('ui.shell.settings')] : null,
                    ]))" />
            </div>
        </header>
        <main class="app-main" id="main" tabindex="-1">{{ $slot }}</main>
    </div>
</div>
<x-shell.confirm-template />
@livewireScripts
</body>
</html>
