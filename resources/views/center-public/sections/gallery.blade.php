{{-- Gallery: grid, mosaic, or a horizontal strip that scrolls inside itself. --}}
@include('center-public.partials.heading', ['section' => $section])
<ul class="cs-gallery" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}" @if($section['layout'] === 'strip') tabindex="0" aria-label="{{ $section['title'] !== '' ? $section['title'] : __('center_site.types.gallery') }}" @endif>
    @foreach($data['items'] as $image)
        <li>
            <figure>
                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy">
                @if($image['caption'] !== '')<figcaption>{{ $image['caption'] }}</figcaption>@endif
            </figure>
        </li>
    @endforeach
</ul>
