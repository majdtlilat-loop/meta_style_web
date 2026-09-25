@php
    use App\View\Landing;

    $plans = $pricing['plans'] ?? [];
    $groups = $pricing['comparison'] ?? [];
    $limits = ($section['options']['show_limits'] ?? true) ? ($pricing['limits'] ?? []) : [];
@endphp
@if($plans !== [] && ($groups !== [] || $limits !== []))
    <x-landing.section :section="$section" :id="$id">
        @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title', 'class' => 'landing-heading--center'])
        <div class="comparison" tabindex="0" role="region" aria-label="{{ Landing::text($section['title'] ?? [], __('platform_landing.pricing.compare_all')) }}">
            <table class="comparison__table" style="--plans: {{ count($plans) }}">
                <thead>
                    <tr>
                        <th scope="col" class="comparison__feature"><span class="sr-only">{{ __('platform_landing.pricing.feature') }}</span></th>
                        @foreach($plans as $plan)
                            <th scope="col" @if($plan['featured']) data-featured @endif>
                                <span class="comparison__plan">{{ $plan['name'] }}</span>
                                <a class="comparison__choose" href="{{ url($plan['register_url']) }}">{{ __('platform_landing.actions.choose_plan') }}</a>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                @foreach($groups as $group)
                    <tbody>
                        <tr class="comparison__group"><th scope="rowgroup" colspan="{{ count($plans) + 1 }}">{{ $group['label'] }}</th></tr>
                        @foreach($group['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach($plans as $plan)
                                    <td @if($plan['featured']) data-featured @endif>
                                        @if($row['values'][$plan['id']] ?? false)
                                            <x-ui.icon name="check" :size="18" class="comparison__yes" /><span class="sr-only">{{ __('platform_landing.pricing.included') }}</span>
                                        @else
                                            <span class="comparison__no" aria-hidden="true">—</span><span class="sr-only">{{ __('platform_landing.pricing.not_included') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
                @if($limits !== [])
                    <tbody>
                        <tr class="comparison__group"><th scope="rowgroup" colspan="{{ count($plans) + 1 }}">{{ __('platform_landing.pricing.limits') }}</th></tr>
                        @foreach($limits as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                @foreach($plans as $plan)
                                    <td @if($plan['featured']) data-featured @endif>{{ $row['values'][$plan['id']] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endif
            </table>
        </div>
    </x-landing.section>
@endif
