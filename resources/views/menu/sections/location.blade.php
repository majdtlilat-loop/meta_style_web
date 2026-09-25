@if ($menu['branch'])
    <section>
        <h2>{{ __('menu_public.find_us') }}</h2>
        @if ($menu['branch']->address)
            <p>{{ $menu['branch']->address->get($locale) }}</p>
        @endif

        @if (($config['show_hours'] ?? true) && $menu['hours'] !== [])
            <table class="hours" aria-label="{{ __('menu_public.hours') }}">
                @foreach ($menu['hours'] as $row)
                    <tr>
                        <td>{{ __('menu_public.days.'.$row['day']) }}</td>
                        <td dir="ltr">{{ $row['times'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if (($config['show_map'] ?? true) && $menu['contact']['map_url'] !== null)
            <p><a href="{{ $menu['contact']['map_url'] }}" target="_blank" rel="noopener nofollow">{{ __('menu_public.open_in_maps') }}</a></p>
        @endif
    </section>
@endif
