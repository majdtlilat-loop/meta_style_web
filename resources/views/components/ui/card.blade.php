@props([
    'title' => null,
    'description' => null,
    'flush' => false,
    'tag' => 'section',
])
<{{ $tag }} {{ $attributes->class(['card', 'card--flush' => $flush || $title]) }}>
    @if($title)
        <header class="card__header">
            <div>
                <h2>{{ $title }}</h2>
                @if($description)<p>{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="cluster cluster--tight">{{ $actions }}</div>@endisset
        </header>
        @if($flush)
            {{ $slot }}
        @else
            <div class="card__body">{{ $slot }}</div>
        @endif
    @else
        {{ $slot }}
    @endif
    @isset($footer)<footer class="card__footer">{{ $footer }}</footer>@endisset
</{{ $tag }}>
