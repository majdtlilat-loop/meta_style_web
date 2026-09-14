{{-- Links are hidden by permission for orientation only; the routes and the
     actions behind them enforce authorization themselves. --}}
<nav class="center-nav">
    <a href="{{ route('center.dashboard') }}" wire:navigate>{{ __('Overview') }}</a>

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::StaffView))
        <a href="{{ route('center.staff') }}" wire:navigate>{{ __('Staff') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::RoleView))
        <a href="{{ route('center.roles') }}" wire:navigate>{{ __('Roles') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::BranchView))
        <a href="{{ route('center.branches') }}" wire:navigate>{{ __('Branches') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::ServiceView))
        <a href="{{ route('center.catalog') }}" wire:navigate>{{ __('Services') }}</a>
    @endif

    {{-- Either grant reaches the calendar. A stylist holding only
         `appointment.view_own` sees their own day there (Phase 7 §30). --}}
    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::AppointmentView)
        || auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::AppointmentViewOwn))
        <a href="{{ route('center.calendar') }}" wire:navigate>{{ __('Calendar') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::JourneyView)
        || auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::JourneyViewOwn))
        <a href="{{ route('center.board') }}" wire:navigate>{{ __('Today') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::QueueView))
        <a href="{{ route('center.queue') }}" wire:navigate>{{ __('Queue') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::SaleCreate)
        || auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::SaleFinalize))
        <a href="{{ route('center.pos') }}" wire:navigate>{{ __('Till') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::SaleView))
        <a href="{{ route('center.sales') }}" wire:navigate>{{ __('Sales') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::ResourceView))
        <a href="{{ route('center.resources') }}" wire:navigate>{{ __('Resources') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::CustomerView))
        <a href="{{ route('center.customers') }}" wire:navigate>{{ __('Customers') }}</a>
    @endif

    @if (auth()->user()?->hasPermission(App\Kernel\Authorization\Permission::MenuView))
        <a href="{{ route('center.menu') }}" wire:navigate>{{ __('Menu') }}</a>
    @endif

    <form method="POST" action="{{ route('logout') }}" style="margin-inline-start:auto">
        @csrf
        <button type="submit">{{ __('Sign out') }}</button>
    </form>
</nav>
