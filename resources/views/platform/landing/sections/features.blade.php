@php
    use App\View\Landing;

    $items = array_values(array_filter($section['items'] ?? [], fn (array $item): bool => ($item['enabled'] ?? true) && (Landing::text($item['title'] ?? []) !== '' || Landing::text($item['body'] ?? []) !== '')));
    $layout = $section['layout'] ?? 'grid';
    $hasMedia = ($section['image'] ?? '') !== '' || ($section['video'] ?? '') !== '';
@endphp
<x-landing.section :section="$section" :id="$id">
    <div class="landing-cards-layout" data-layout="{{ $layout }}" @if($hasMedia) data-media="{{ $section['media_position'] ?? 'end' }}" @endif>
        <div class="landing-cards-layout__intro">
            @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title'])
            @if($hasMedia)
                @include('platform.landing.partials.media', ['image' => $section['image'] ?? '', 'video' => $section['video'] ?? '', 'poster' => $section['poster'] ?? '', 'alt' => $section['image_alt'] ?? [], 'class' => 'landing-cards-layout__media'])
            @endif
            @if(Landing::showsCta($section['cta'] ?? []))
                <p class="landing-actions"><a class="{{ Landing::ctaClass($section['cta']) }}" href="{{ Landing::href($section['cta']) }}" @if($section['cta']['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($section['cta']['label']) }}</a></p>
            @endif
        </div>
        @if($items !== [])
            <ul class="landing-cards landing-cards--{{ $section['type'] }}" role="list" style="--landing-columns: {{ $layout === 'split' ? min(2, (int) ($section['columns'] ?? 3)) : (int) ($section['columns'] ?? 3) }}">
                @foreach($items as $item)
                    @php
                        $title = Landing::text($item['title'] ?? []);
                        $image = Landing::media($item['image'] ?? null);
                        $url = (string) ($item['url'] ?? '');
                    @endphp
                    <li class="landing-card">
                        @if($image)
                            <img class="landing-card__image" src="{{ $image }}" alt="{{ Landing::text($item['image_alt'] ?? []) }}" loading="lazy" decoding="async">
                        @else
                            <span class="landing-card__icon"><x-ui.icon :name="$item['icon'] ?? 'sparkles'" :size="22" /></span>
                        @endif
                        <div class="landing-card__text">
                            @if($title !== '')
                                <h3 class="landing-card__title">
                                    @if($url !== '')<a href="{{ Landing::href(['url' => $url]) }}">{{ $title }}</a>@else{{ $title }}@endif
                                </h3>
                            @endif
                            @if(($body = Landing::text($item['body'] ?? [])) !== '')<p>{{ $body }}</p>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-landing.section>
