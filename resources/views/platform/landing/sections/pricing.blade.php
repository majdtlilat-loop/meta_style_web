@php
    use App\View\Landing;

    $plans = $pricing['plans'] ?? [];
    $cycles = array_keys(array_filter($pricing['cycles'] ?? []));
    $default = in_array($section['options']['default_cycle'] ?? 'monthly', $cycles, true) ? $section['options']['default_cycle'] : ($cycles[0] ?? 'monthly');
    $bestSaving = collect($plans)->max(fn (array $plan): int => (int) ($plan['saving'] ?? 0));
    $compareAnchor = null;
    if ($section['options']['show_comparison_link'] ?? true) {
        foreach ($content['section_order'] ?? [] as $candidate) {
            $other = $content['sections'][$candidate] ?? [];
            if (($other['type'] ?? '') === 'comparison' && ($other['enabled'] ?? true) && ($other['anchor'] ?? '') !== '') {
                $compareAnchor = $other['anchor'];
                break;
            }
        }
    }
    $featureLimit = 8;
@endphp
<x-landing.section :section="$section" :id="$id">
    <div x-data="{ cycle: @js($default) }">
        @include('platform.landing.partials.heading', ['section' => $section, 'headingId' => 'landing-'.$id.'-title', 'class' => 'landing-heading--center'])

        @if($plans === [])
            <div class="landing-empty">
                <x-ui.icon name="sparkles" :size="24" />
                <h3>{{ __('platform_landing.plans.empty_title') }}</h3>
                <p>{{ __('platform_landing.plans.empty_body') }}</p>
                <a class="button" href="{{ route('register') }}">{{ __('platform_landing.actions.create_center') }}</a>
            </div>
        @else
            @if(count($cycles) > 1)
                <div class="pricing-toggle" role="group" aria-label="{{ __('platform_landing.pricing.cycle_label') }}">
                    <button type="button" class="pricing-toggle__option" x-on:click="cycle = 'monthly'" :aria-pressed="cycle === 'monthly' ? 'true' : 'false'" aria-pressed="{{ $default === 'monthly' ? 'true' : 'false' }}">{{ __('platform_landing.pricing.monthly') }}</button>
                    <button type="button" class="pricing-toggle__option" x-on:click="cycle = 'yearly'" :aria-pressed="cycle === 'yearly' ? 'true' : 'false'" aria-pressed="{{ $default === 'yearly' ? 'true' : 'false' }}">
                        {{ __('platform_landing.pricing.yearly') }}
                        @if($bestSaving > 0)<span class="pricing-toggle__saving">{{ __('platform_landing.pricing.save_up_to', ['percent' => $bestSaving]) }}</span>@endif
                    </button>
                </div>
            @endif

            <ul class="pricing-grid" role="list" style="--landing-columns: {{ min(count($plans), 4) }}">
                @foreach($plans as $plan)
                    @php
                        $features = $plan['features'] ?? [];
                        // "Choose plan" always names a cycle this plan is sold on.
                        $offered = array_values(array_filter(['monthly', 'yearly'], fn (string $cycle): bool => $plan[$cycle] !== null));
                        $fallback = in_array($default, $offered, true) ? $default : ($offered[0] ?? 'monthly');
                    @endphp
                    {{-- Four blocks per card, aligned across cards: intro, price, action, features. --}}
                    <li class="pricing-card" @if($plan['featured']) data-featured @endif>
                        <div class="pricing-card__intro">
                            <div class="pricing-card__head">
                                <h3 class="pricing-card__name">{{ $plan['name'] }}</h3>
                                @if($plan['featured'])<span class="pricing-card__badge">{{ __('platform_landing.pricing.featured') }}</span>@endif
                            </div>
                            @if($plan['description'] !== '')<p class="pricing-card__description">{{ $plan['description'] }}</p>@endif
                        </div>

                        <div class="pricing-card__pricing">
                            @foreach(['monthly', 'yearly'] as $cycle)
                                @continue(! in_array($cycle, $cycles, true))
                                <div class="pricing-card__price" x-show="cycle === '{{ $cycle }}'" @if($cycle !== $default) x-cloak @endif>
                                    @if($plan[$cycle])
                                        <p class="pricing-card__amount"><strong>{{ $plan[$cycle]['formatted'] }}</strong><span>{{ __('platform_landing.pricing.per.'.$cycle) }}</span></p>
                                        @if($cycle === 'yearly')
                                            <p class="pricing-card__note">{{ __('platform_landing.pricing.per_month_equivalent', ['amount' => $plan['yearly']['per_month']]) }}
                                                @if(($plan['saving'] ?? 0) > 0)<span class="pricing-card__saving">{{ __('platform_landing.pricing.save', ['percent' => $plan['saving']]) }}</span>@endif
                                            </p>
                                        @else
                                            <p class="pricing-card__note">{{ __('platform_landing.pricing.billed_monthly') }}</p>
                                        @endif
                                    @else
                                        <p class="pricing-card__amount pricing-card__amount--none">{{ __('platform_landing.pricing.not_offered.'.$cycle) }}</p>
                                    @endif
                                </div>
                            @endforeach
                            @if(($plan['trial_days'] ?? 0) > 0)
                                <p class="pricing-card__trial"><x-ui.icon name="clock" :size="16" />{{ trans_choice('platform_landing.pricing.trial', $plan['trial_days'], ['days' => $plan['trial_days']]) }}</p>
                            @endif
                        </div>

                        <a class="{{ $plan['featured'] ? 'button' : 'button button--secondary' }} pricing-card__cta"
                           :href="'{{ url($plan['register_url']) }}&cycle=' + (@js($offered).includes(cycle) ? cycle : @js($fallback))" href="{{ url($plan['register_url']) }}&cycle={{ $fallback }}">{{ __('platform_landing.actions.choose_plan') }}</a>

                        <div class="pricing-card__included">
                            @if($features !== [])
                                <p class="pricing-card__features-title">{{ __('platform_landing.pricing.includes') }}</p>
                                <ul class="pricing-card__features" role="list">
                                    @foreach(array_slice($features, 0, $featureLimit) as $feature)
                                        <li><x-ui.icon name="check" :size="16" />{{ $feature }}</li>
                                    @endforeach
                                </ul>
                                @if(count($features) > $featureLimit)
                                    <p class="pricing-card__more">
                                        @if($compareAnchor)<a href="#{{ $compareAnchor }}">{{ __('platform_landing.pricing.more_features', ['count' => count($features) - $featureLimit]) }}</a>
                                        @else{{ __('platform_landing.pricing.more_features', ['count' => count($features) - $featureLimit]) }}@endif
                                    </p>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @if($compareAnchor)
                <p class="landing-actions landing-actions--center"><a class="text-button" href="#{{ $compareAnchor }}">{{ __('platform_landing.pricing.compare_all') }} <x-ui.icon name="arrow-down" :size="16" /></a></p>
            @endif
        @endif
    </div>
</x-landing.section>
