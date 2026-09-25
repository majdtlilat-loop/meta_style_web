@php
    use App\View\Landing;

    $items = array_values(array_filter($section['items'] ?? [], fn (array $item): bool => ($item['enabled'] ?? true) && Landing::text($item['title'] ?? []) !== ''));
@endphp
@if($items !== [])
    <x-landing.section :section="$section" :id="$id">
        <div class="landing-faq">
            @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title'])
            <div class="landing-faq__list">
                @foreach($items as $item)
                    <details class="landing-faq__item" @if($loop->first) open @endif>
                        <summary><span>{{ Landing::text($item['title']) }}</span><x-ui.icon name="chevron-down" :size="18" /></summary>
                        @if(($answer = Landing::text($item['body'] ?? [])) !== '')<p>{{ $answer }}</p>@endif
                    </details>
                @endforeach
            </div>
        </div>
    </x-landing.section>
@endif
