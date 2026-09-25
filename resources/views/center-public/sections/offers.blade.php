{{-- Memberships or packages the center currently sells. --}}
@include('center-public.partials.heading', ['section' => $section])
<ul class="cs-grid cs-offers" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}">
    @foreach($data['items'] as $offer)
        <li class="cs-card cs-offer">
            <div class="cs-card__body">
                <h3>{{ $offer['name'] }}</h3>
                @if($data['show_prices'])<strong class="cs-price cs-price--large" dir="ltr">{{ $offer['price'] }}</strong>@endif
                <ul class="cs-offer__facts">
                    <li><x-ui.icon name="calendar" size="16" />{{ $offer['term'] }}</li>
                    @if($offer['detail'] !== '')<li><x-ui.icon name="check-circle" size="16" />{{ $offer['detail'] }}</li>@endif
                </ul>
            </div>
        </li>
    @endforeach
</ul>
@if($section['cta'])<div class="cs-actions cs-actions--after" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
