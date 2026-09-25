@php
    use App\View\Landing;

    $items = array_values(array_filter($section['items'] ?? [], fn (array $item): bool => ($item['enabled'] ?? true) && Landing::text($item['title'] ?? []) !== ''));
@endphp
<x-landing.section :section="$section" :id="$id">
    @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title', 'class' => 'landing-heading--center'])
    @if($items !== [])
        <ol class="landing-steps" data-layout="{{ $section['layout'] ?? 'list' }}" style="--landing-columns: {{ min(count($items), (int) ($section['columns'] ?? 3)) }}">
            @foreach($items as $item)
                <li class="landing-step">
                    <span class="landing-step__number" aria-hidden="true">{{ $loop->iteration }}</span>
                    <div>
                        <h3 class="landing-step__title">{{ Landing::text($item['title'] ?? []) }}</h3>
                        @if(($body = Landing::text($item['body'] ?? [])) !== '')<p>{{ $body }}</p>@endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
    @if(Landing::showsCta($section['cta'] ?? []))
        <p class="landing-actions landing-actions--center"><a class="{{ Landing::ctaClass($section['cta']) }}" href="{{ Landing::href($section['cta']) }}" @if($section['cta']['new_tab'] ?? false) target="_blank" rel="noopener" @endif>{{ Landing::text($section['cta']['label']) }}</a></p>
    @endif
</x-landing.section>
