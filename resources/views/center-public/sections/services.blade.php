{{-- Services (all, by category, or hand-picked), always from the live catalog. --}}
@include('center-public.partials.heading', ['section' => $section])
<ul class="cs-grid cs-services" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}">
    @foreach($data['items'] as $service)
        <li class="cs-card cs-service">
            @if($data['show_images'] && $service['image'] && $section['layout'] !== 'compact')
                <img class="cs-card__image" src="{{ $service['image']['url'] }}" alt="{{ $service['image']['alt'] ?: $service['name'] }}" loading="lazy">
            @endif
            <div class="cs-card__body">
                @if($service['category'])<span class="cs-card__eyebrow">{{ $service['category'] }}</span>@endif
                <h3>{{ $service['name'] }}</h3>
                @if($service['description'] && $section['layout'] !== 'compact')<p>{{ $service['description'] }}</p>@endif
                <div class="cs-service__meta">
                    @if($data['show_duration'])<span><x-ui.icon name="clock" size="16" />{{ __('center_site.minutes', ['count' => $service['duration_minutes']]) }}</span>@endif
                    @if($data['show_prices'])<strong class="cs-price">@if($service['price_from']){{ __('center_site.from') }} @endif<span dir="ltr">{{ $service['price'] }}</span></strong>@endif
                </div>
                @if($data['href'] && ($data['link'] !== 'booking' || $service['bookable']))
                    <a class="cs-card__link" href="{{ $data['href'] }}">{{ $data['link'] === 'booking' ? __('center_site.book') : __('center_site.details') }}<x-ui.icon name="arrow-right" size="16" /></a>
                @endif
            </div>
        </li>
    @endforeach
</ul>
@if($section['cta'])<div class="cs-actions cs-actions--after" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
