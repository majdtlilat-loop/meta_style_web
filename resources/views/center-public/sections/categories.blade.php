{{-- Categories from the live catalog; each opens the full service list. --}}
@include('center-public.partials.heading', ['section' => $section])
<ul class="cs-grid cs-categories" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}">
    @foreach($data['items'] as $category)
        <li class="cs-card cs-category">
            <a href="{{ $data['href'] }}">
                @if($data['show_images'] && $category['image'] && $section['layout'] !== 'chips')
                    <img class="cs-card__image" src="{{ $category['image']['url'] }}" alt="{{ $category['image']['alt'] ?: $category['name'] }}" loading="lazy">
                @endif
                <span class="cs-card__body">
                    <strong>{{ $category['name'] }}</strong>
                    @if($category['description'] && $section['layout'] !== 'chips')<span class="cs-muted">{{ $category['description'] }}</span>@endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
@if($section['cta'])<div class="cs-actions cs-actions--after" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
