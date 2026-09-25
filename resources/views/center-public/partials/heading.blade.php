{{-- A section heading: icon + eyebrow, title, subtitle, body. Params: $section. --}}
@if($section['eyebrow'] !== '' || $section['title'] !== '' || $section['subtitle'] !== '' || $section['body'] !== '')
    <header class="cs-heading" data-align="{{ $section['alignment'] }}">
        @if($section['eyebrow'] !== '')<p class="cs-eyebrow"><x-ui.icon :name="$section['icon']" size="16" />{{ $section['eyebrow'] }}</p>@endif
        @if($section['title'] !== '')<h2>{{ $section['title'] }}</h2>@endif
        @if($section['subtitle'] !== '')<p class="cs-heading__subtitle">{{ $section['subtitle'] }}</p>@endif
        @if($section['body'] !== '' && ! ($withoutBody ?? false))<p class="cs-heading__body">{{ $section['body'] }}</p>@endif
    </header>
@endif
