{{-- Opening hours from each branch's real weekly schedule. --}}
@include('center-public.partials.heading', ['section' => $section])
<div class="cs-grid cs-hours-grid" data-layout="{{ $section['layout'] }}" data-columns="{{ $data['single'] ? 1 : $section['columns'] }}">
    @foreach($data['branches'] as $branch)
        <div class="cs-card">
            <div class="cs-card__body">
                @unless($data['single'])<h3>{{ $branch['name'] }}</h3>@endunless
                <dl class="cs-hours">
                    @foreach($branch['days'] as $day)
                        <div><dt>{{ $day['day'] }}</dt><dd dir="ltr">{{ $day['value'] }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </div>
    @endforeach
</div>
