{{-- The team members the owner chose — name from the staff record, title and photo written for the site. --}}
@include('center-public.partials.heading', ['section' => $section])
<ul class="cs-grid cs-team" data-layout="{{ $section['layout'] }}" data-columns="{{ $section['columns'] }}">
    @foreach($data['items'] as $member)
        <li class="cs-card cs-member">
            @if($member['photo'])
                <img class="cs-member__photo" src="{{ $member['photo']['url'] }}" alt="{{ $member['photo']['alt'] }}" loading="lazy">
            @else
                <span class="cs-member__photo cs-member__initial" aria-hidden="true">{{ $member['initial'] }}</span>
            @endif
            <div class="cs-card__body">
                <h3>{{ $member['name'] }}</h3>
                @if($member['title'] !== '')<span class="cs-card__eyebrow">{{ $member['title'] }}</span>@endif
                @if($member['bio'] !== '')<p>{{ $member['bio'] }}</p>@endif
            </div>
        </li>
    @endforeach
</ul>
@if($section['cta'])<div class="cs-actions cs-actions--after" data-align="{{ $section['alignment'] }}">@include('center-public.partials.cta', ['cta' => $section['cta']])</div>@endif
