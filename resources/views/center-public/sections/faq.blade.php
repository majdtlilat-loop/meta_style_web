{{-- Questions and answers as native disclosure widgets. --}}
@include('center-public.partials.heading', ['section' => $section])
<div class="cs-faq" data-layout="{{ $section['layout'] }}">
    @foreach($data['items'] as $item)
        <details class="cs-faq__item">
            <summary><span>{{ $item['question'] }}</span><x-ui.icon name="chevron-down" size="18" /></summary>
            <p>{{ $item['answer'] }}</p>
        </details>
    @endforeach
</div>
@if($section['cta'])<div class="cs-actions cs-actions--after" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
