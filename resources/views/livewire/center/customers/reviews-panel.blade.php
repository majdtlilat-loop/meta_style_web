{{--
    What this customer said about their visits. Read-only here — moderation
    lives on the Reviews page. $locked: the center never collected a review and
    does not own `reviews` — the compact upgrade notice, and nothing is read.
--}}
<div class="stack">
    @if($offer)
        <x-manager.feature-locked :offer="$offer" compact :history="! $locked" />
    @endif
    @unless($locked)
        <x-ui.card :title="__('manager_customers.reviews.customer_title')" flush>
            <x-slot:actions>
                <a class="button button--ghost button--sm" href="{{ $reviewsUrl }}" wire:navigate><x-ui.icon name="reviews" size="16" />{{ __('manager_customers.reviews.open_reviews') }}</a>
            </x-slot:actions>
            @if($reviews === [])
                <x-ui.empty-state compact icon="reviews" :title="__('manager_customers.reviews.customer_none')" />
            @else
                <div class="review-list crm-reviews">
                    @foreach($reviews as $review)
                        @include('livewire.center.reviews.card', ['review' => $review, 'canModerate' => false])
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endunless
</div>
