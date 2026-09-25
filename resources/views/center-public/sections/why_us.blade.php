{{-- Why choose us: the owner's highlights. --}}
@include('center-public.partials.heading', ['section' => $section])
@if($data['items'] !== [])
    <ul class="cs-grid cs-features" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}">
        @foreach($data['items'] as $item)
            <li class="cs-card cs-feature">
                @if($item['image'])
                    <img class="cs-card__image" src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] }}" loading="lazy">
                @else
                    <span class="cs-icon" aria-hidden="true"><x-ui.icon :name="$item['icon']" size="22" /></span>
                @endif
                <div class="cs-card__body">
                    @if($item['title'] !== '')<h3>{{ $item['title'] }}</h3>@endif
                    @if($item['body'] !== '')<p>{{ $item['body'] }}</p>@endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
@if($section['cta'])<div class="cs-actions cs-actions--after" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
