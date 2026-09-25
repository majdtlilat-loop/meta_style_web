{{-- About: the owner's own words, optional media and highlights. --}}
<div class="cs-split" data-media="{{ $section['media']['image'] || $section['media']['video'] ? $section['media']['position'] : 'none' }}">
    <div class="cs-split__copy">
        @include('center-public.partials.heading', ['section' => $section])
        @if($data['items'] !== [])
            <ul class="cs-highlights">
                @foreach($data['items'] as $item)
                    <li>
                        <span class="cs-icon" aria-hidden="true"><x-ui.icon :name="$item['icon']" size="20" /></span>
                        <div>
                            @if($item['title'] !== '')<strong>{{ $item['title'] }}</strong>@endif
                            @if($item['body'] !== '')<p>{{ $item['body'] }}</p>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        @if($section['cta'])<div class="cs-actions">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
    </div>
    @include('center-public.partials.media', ['media' => $section['media']])
</div>
