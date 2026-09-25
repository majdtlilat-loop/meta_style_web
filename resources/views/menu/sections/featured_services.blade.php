@if ($menu['featured']->isNotEmpty())
    <section>
        <h2>{{ __('menu_public.featured') }}</h2>
        <div class="services">
            @foreach ($menu['featured'] as $service)
                @include('menu.partials.service', ['service' => $service])
            @endforeach
        </div>
    </section>
@endif
