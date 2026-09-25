@props([
    'name' => '',
    'email' => null,
    'role' => null,
    'logout',
    'links' => [],
    'initials' => null,
])
{{-- `initials` is optional: the Manager passes them from its shell model; otherwise they come from the name. --}}
<details class="topbar-popover" data-popover>
    <summary class="account-trigger" aria-label="{{ __('ui.shell.account_menu') }}" aria-haspopup="menu">
        <span class="avatar" aria-hidden="true">{{ $initials ?? \App\View\Manager\PersonInitials::of($name) }}</span>
        <x-ui.icon name="chevron-down" />
    </summary>
    <div class="topbar-popover__panel user-menu" role="menu">
        <div class="user-menu__identity">
            <span class="avatar avatar--lg" aria-hidden="true">{{ $initials ?? \App\View\Manager\PersonInitials::of($name) }}</span>
            <div>
                <strong>{{ $name }}</strong>
                @if($email)<span class="ltr">{{ $email }}</span>@endif
                @if($role)<span>{{ $role }}</span>@endif
            </div>
        </div>
        @if($links !== [])
            <div class="menu-separator"></div>
            @foreach($links as $link)
                <a class="menu-item" role="menuitem" href="{{ $link['href'] }}" wire:navigate><x-ui.icon :name="$link['icon']" />{{ $link['label'] }}</a>
            @endforeach
        @endif
        <div class="menu-separator"></div>
        <form method="post" action="{{ $logout }}">
            @csrf
            <button class="menu-item" role="menuitem" type="submit"><x-ui.icon name="logout" />{{ __('ui.shell.sign_out') }}</button>
        </form>
    </div>
</details>
