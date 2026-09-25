@props(['offer', 'compact' => false, 'history' => false])
{{--
    What a center sees where a plan feature is missing. Presentation only:
    every value comes from App\View\Manager\FeatureOffer (the real plan
    catalog and the center's own subscription). Locked pages load no data and
    their Actions still refuse on the server.

    compact  a one-line notice for a page that stays readable (history).
--}}
@if($compact)
    <div {{ $attributes->class(['feature-lock-notice']) }} role="status">
        <x-ui.icon name="lock" size="18" />
        <p>
            {{ $history ? __('manager_features.ui.history_notice', ['feature' => $offer['name']]) : __('manager_features.ui.upgrade_body', ['feature' => $offer['name']]) }}
            @if($offer['available_from'])<span class="feature-lock-notice__plan">{{ $offer['lock_label'] }}</span>@endif
        </p>
        @if($offer['links']['plans'])
            <a class="button button--secondary button--sm" href="{{ $offer['links']['plans'] }}" wire:navigate>{{ __('manager_features.ui.view_plans') }}</a>
        @endif
    </div>
@else
    <section {{ $attributes->class(['feature-lock']) }} aria-labelledby="feature-lock-{{ $offer['key'] }}" x-data="{ cycle: 'monthly' }">
        <header class="feature-lock__header">
            <span class="feature-lock__icon" aria-hidden="true"><x-ui.icon name="lock" /></span>
            <div>
                <p class="eyebrow">{{ __('manager_features.ui.eyebrow') }}</p>
                <h2 id="feature-lock-{{ $offer['key'] }}">{{ $offer['name'] }}</h2>
                @if($offer['summary'])<p class="feature-lock__summary">{{ $offer['summary'] }}</p>@endif
            </div>
        </header>

        <div class="feature-lock__body">
            @if($offer['capabilities'] !== [])
                <div class="feature-lock__capabilities">
                    <h3>{{ __('manager_features.ui.what_you_get') }}</h3>
                    <ul>
                        @foreach($offer['capabilities'] as $capability)
                            <li><x-ui.icon name="check-circle" size="18" />{{ $capability }}</li>
                        @endforeach
                    </ul>
                    @if($offer['requires'] !== [])
                        <p class="feature-lock__requires">{{ __('manager_features.ui.also_needs') }}: {{ implode(' · ', $offer['requires']) }}</p>
                    @endif
                </div>
            @endif

            <div class="feature-lock__plans">
                @if($offer['current'])
                    <p class="feature-lock__current">
                        <span>{{ __('manager_features.ui.your_plan') }}</span>
                        <strong>{{ $offer['current']['name'] }}</strong>
                        <x-ui.status :value="$offer['current']['status']" :label="$offer['current']['status_label']" />
                        @if($offer['current']['price'] && $offer['current']['cycle_label'])<span class="muted">{{ $offer['current']['price'] }} · {{ $offer['current']['cycle_label'] }}</span>@endif
                    </p>
                @endif

                @if($offer['state'] === 'upgrade' && $offer['plans'] !== [])
                    <div class="feature-lock__plans-head">
                        <h3>{{ __('manager_features.ui.included_in') }}</h3>
                        <div class="segmented segmented--sm" role="group" aria-label="{{ __('manager_features.ui.monthly') }} / {{ __('manager_features.ui.yearly') }}">
                            <button type="button" x-on:click="cycle = 'monthly'" x-bind:aria-pressed="cycle === 'monthly' ? 'true' : 'false'">{{ __('manager_features.ui.monthly') }}</button>
                            <button type="button" x-on:click="cycle = 'yearly'" x-bind:aria-pressed="cycle === 'yearly' ? 'true' : 'false'">{{ __('manager_features.ui.yearly') }}</button>
                        </div>
                    </div>
                    <ul class="feature-lock__plan-list">
                        @foreach($offer['plans'] as $plan)
                            <li @class(['feature-plan', 'is-featured' => $plan['featured'], 'is-current' => $plan['current']])>
                                <div class="feature-plan__name">
                                    <strong>{{ $plan['name'] }}</strong>
                                    @if($plan['current'])<span class="badge">{{ __('manager_features.ui.current') }}</span>
                                    @elseif($plan['featured'])<span class="badge" data-tone="primary">{{ __('manager_features.ui.recommended') }}</span>@endif
                                </div>
                                <div class="feature-plan__price">
                                    <span x-show="cycle === 'monthly'">{{ $plan['monthly'] ? __('manager_features.ui.per_month', ['price' => $plan['monthly']]) : '—' }}</span>
                                    <span x-show="cycle === 'yearly'" x-cloak>{{ $plan['yearly'] ? __('manager_features.ui.per_year', ['price' => $plan['yearly']]) : '—' }}</span>
                                    @if($plan['saving'])<small x-show="cycle === 'yearly'" x-cloak>{{ __('manager_features.ui.save', ['percent' => $plan['saving']]) }}</small>@endif
                                </div>
                                @if($plan['limits'] !== [])
                                    <dl class="feature-plan__limits">
                                        @foreach($plan['limits'] as $limit)
                                            <div><dt>{{ $limit['label'] }}</dt><dd>{{ $limit['value'] }}</dd></div>
                                        @endforeach
                                    </dl>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="feature-lock__note">{{ $offer['state'] === 'unavailable' ? __('manager_features.ui.unavailable_body', ['feature' => $offer['name']]) : __('manager_features.ui.contact_body', ['feature' => $offer['name']]) }}</p>
                @endif
            </div>
        </div>

        <footer class="feature-lock__actions">
            {{ $slot }}
            @if($offer['links']['support'])
                <a class="button button--secondary" href="{{ $offer['links']['support'] }}" wire:navigate><x-ui.icon name="support" size="18" />{{ __('manager_features.ui.contact') }}</a>
            @endif
            @if($offer['links']['plans'])
                <a class="button" href="{{ $offer['links']['plans'] }}" wire:navigate><x-ui.icon name="sparkles" size="18" />{{ __('manager_features.ui.view_plans') }}</a>
            @endif
        </footer>
    </section>
@endif
