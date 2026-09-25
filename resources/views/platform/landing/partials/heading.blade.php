@php use App\View\Landing; @endphp
@php
    $eyebrow = Landing::text($section['eyebrow'] ?? []);
    $title = Landing::text($section['title'] ?? []);
    $body = Landing::text($section['body'] ?? []);
@endphp
@if($eyebrow !== '' || $title !== '' || $body !== '')
    <header class="landing-heading {{ $class ?? '' }}">
        @if($eyebrow !== '')<p class="landing-eyebrow">{{ $eyebrow }}</p>@endif
        @if($title !== '')<h2 class="landing-heading__title" id="{{ $headingId ?? '' }}">{{ $title }}</h2>@endif
        @if($body !== '')<p class="landing-heading__body">{{ $body }}</p>@endif
    </header>
@endif
