{{-- The aggregate rating only: never a review's words or a reviewer. --}}
<div class="cs-rating" data-layout="{{ $section['layout'] }}">
    <div class="cs-rating__copy">
        @include('center-public.partials.heading', ['section' => $section])
        @if($section['cta'])<div class="cs-actions">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
    </div>
    <div class="cs-rating__score">
        <strong class="cs-rating__value" dir="ltr">{{ $data['average'] }}</strong>
        <span class="cs-stars" role="img" aria-label="{{ __('center_site.rating.label', ['average' => $data['average']]) }}">
            @foreach($data['stars'] as $star)<span class="cs-star" data-fill="{{ $star }}" aria-hidden="true"><x-ui.icon name="star" size="20" /></span>@endforeach
        </span>
        @if($data['show_count'])<span class="cs-muted">{{ trans_choice('center_site.rating.count', $data['count'], ['count' => $data['count']]) }}</span>@endif
        @if($data['distribution'] !== [])
            <ul class="cs-distribution">
                @foreach($data['distribution'] as $row)
                    <li><span dir="ltr">{{ $row['score'] }}</span><span class="cs-distribution__bar"><span style="inline-size: {{ $row['percent'] }}%"></span></span><span class="cs-muted" dir="ltr">{{ $row['percent'] }}%</span></li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
