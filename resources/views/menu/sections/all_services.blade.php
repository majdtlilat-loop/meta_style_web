<section>
    <h2>{{ __('menu_public.services') }}</h2>

    @if ($menu['services']->isEmpty())
        <p class="empty">{{ __('menu_public.empty') }}</p>
    @endif

    @foreach ($menu['groups'] as $group)
        @continue($group['services']->isEmpty())

        @if ($group['title'] !== null)
            <h3 class="group" @if ($group['anchor']) id="{{ $group['anchor'] }}" @endif>{{ $group['title']->get($locale) }}</h3>
        @elseif (count($menu['groups']) > 1)
            <h3 class="group">{{ __('menu_public.more') }}</h3>
        @endif

        <div class="services">
            @foreach ($group['services'] as $service)
                @include('menu.partials.service', ['service' => $service])
            @endforeach
        </div>
    @endforeach
</section>
