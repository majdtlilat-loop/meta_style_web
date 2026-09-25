{{--
    The rating picture for the chosen branch and days. Every number is
    RatingSummary's; widths are prepared by Reviews\Summary.
--}}
<div class="reviews-summary-grid" wire:loading.class="is-refreshing">
    <section class="card review-summary-card" aria-label="{{ __('manager_customers.reviews.summary') }}">
        @if($count === 0)
            <x-ui.empty-state compact icon="reviews" :title="__('manager_customers.reviews.no_ratings')" />
        @else
            <div class="review-summary">
                <div class="review-summary__score">
                    <strong class="tabular">{{ number_format((float) $average, 1) }}</strong>
                    <span class="review-stars" aria-hidden="true">{{ $stars }}</span>
                    <span class="muted">{{ trans_choice('manager_customers.reviews.count', $count, ['n' => number_format($count)]) }}</span>
                </div>
                <ul class="review-summary__bars">
                    @foreach($distribution as $row)
                        <li title="{{ __('manager_customers.reviews.share', ['n' => $row['count'], 'percent' => $row['share']]) }}">
                            <span class="tabular">{{ $row['value'] }} ★</span>
                            <span class="hbar-track"><span class="hbar-fill" style="inline-size: {{ $row['width'] }}%"></span></span>
                            <span class="tabular muted">{{ $row['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <x-ui.card :title="__('manager_customers.reviews.by_service')">
        @if($services === [])
            <p class="muted">{{ __('manager_customers.reviews.none_rated') }}</p>
        @else
            <ul class="hbars">
                @foreach($services as $row)
                    <li class="hbars__row">
                        <span class="hbars__label">{{ $row['name'] }}</span>
                        <span class="hbars__track"><span style="inline-size: {{ $row['width'] }}%"></span></span>
                        <span class="hbars__value"><strong>{{ number_format($row['average'], 1) }}</strong><small>{{ trans_choice('manager_customers.reviews.ratings', $row['count'], ['n' => $row['count']]) }}</small></span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('manager_customers.reviews.by_employee')">
        @if($employees === [])
            <p class="muted">{{ __('manager_customers.reviews.none_rated') }}</p>
        @else
            <ul class="hbars">
                @foreach($employees as $row)
                    <li class="hbars__row">
                        <span class="hbars__label">{{ $row['name'] }}</span>
                        <span class="hbars__track"><span style="inline-size: {{ $row['width'] }}%"></span></span>
                        <span class="hbars__value"><strong>{{ number_format($row['average'], 1) }}</strong><small>{{ trans_choice('manager_customers.reviews.ratings', $row['count'], ['n' => $row['count']]) }}</small></span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
