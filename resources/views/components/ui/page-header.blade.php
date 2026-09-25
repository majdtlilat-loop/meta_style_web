@props([
    'title',
    'subtitle' => null,
    'eyebrow' => null,
    'breadcrumbs' => [],
])
{{--
    Every page opens the same way: where you are, what this is, what you can do.
    `actions` holds the primary action (rightmost) and any secondary ones;
    `meta` holds status badges or facts shown under the title.
--}}
<header {{ $attributes->class(['page-header']) }}>
    <div>
        @if($breadcrumbs !== [])
            <nav aria-label="Breadcrumb">
                <ol class="breadcrumbs">
                    @foreach($breadcrumbs as $crumb)
                        <li>@if(! empty($crumb['href']))<a href="{{ $crumb['href'] }}" wire:navigate>{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif</li>
                    @endforeach
                </ol>
            </nav>
        @elseif($eyebrow)
            <p class="eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1>{{ $title }}</h1>
        @if($subtitle)<p class="page-header__subtitle">{{ $subtitle }}</p>@endif
        @isset($meta)<div class="page-header__meta">{{ $meta }}</div>@endisset
    </div>
    @isset($actions)<div class="page-header__actions">{{ $actions }}</div>@endisset
</header>
