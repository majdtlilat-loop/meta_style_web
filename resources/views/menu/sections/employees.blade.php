@if ($menu['employees']->isNotEmpty())
    <section>
        <h2>{{ __('menu_public.our_team') }}</h2>
        {{-- Names only. Nothing else about staff is loaded at all. --}}
        <div class="people">
            @foreach ($menu['employees'] as $employee)
                <span>{{ $employee->name?->get($locale) }}</span>
            @endforeach
        </div>
    </section>
@endif
