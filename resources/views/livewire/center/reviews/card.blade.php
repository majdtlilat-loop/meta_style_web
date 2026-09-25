{{--
    One review card. $review comes from BuildsReviewCards (presenter allow-list
    plus labels); the comment is customer-authored and always escaped (§56).
    With $canModerate the card carries the hide / flag / unhide controls.
--}}
<article class="review-card" wire:key="review-{{ $review['id'] }}" data-status="{{ $review['status'] }}">
    <header class="review-card__head">
        <span class="review-card__rating" data-low="{{ $review['low'] ? 'true' : 'false' }}">
            <span class="review-stars" aria-hidden="true">{{ $review['stars'] }}</span>
            <strong class="tabular">{{ $review['overall_rating'] }} / 5</strong>
        </span>
        <x-ui.status :tone="$review['status_tone']" :label="$review['status_label']" />
    </header>
    <p class="review-card__meta">
        @if($review['branch'] !== '')<span>{{ $review['branch'] }}</span> · @endif
        <time datetime="{{ $review['submitted_at'] }}" title="{{ $review['when_full'] }}">{{ $review['when'] }}</time>
    </p>

    @if($review['comment'] !== null)
        <blockquote class="review-card__comment prewrap">{{ $review['comment'] }}</blockquote>
    @endif

    @if($review['ratings'] !== [])
        <div class="chip-list">
            @foreach($review['ratings'] as $detail)
                <span class="chip"><x-ui.icon :name="$detail['dimension'] === 'employee' ? 'user' : 'scissors'" size="12" />{{ $detail['target'] !== '' ? $detail['target'] : '—' }} · {{ $detail['rating'] }}/5</span>
            @endforeach
        </div>
    @endif

    @if($review['moderation'] !== null)
        <p class="review-card__moderation">
            <x-ui.icon name="shield" size="14" />
            {{ __('manager_customers.reviews.moderated_by', ['who' => $review['moderation']['by'] ?? '—', 'date' => $review['moderated_when']]) }}@if($review['moderation']['reason'] !== null) — {{ $review['moderation']['reason'] }}@endif
        </p>
    @endif

    @if($canModerate ?? false)
        @if(($acting ?? '') === $review['id'])
            <div class="review-card__moderate">
                <x-ui.field :label="__('manager_customers.reviews.reason')" for="moderate-{{ $review['id'] }}" name="reason" :help="__('manager_customers.reviews.reason_help')">
                    <input id="moderate-{{ $review['id'] }}" type="text" maxlength="190" wire:model="reason" autocomplete="off">
                </x-ui.field>
                <div class="cluster cluster--tight">
                    <button class="button button--sm button--danger" type="button" wire:click="hide" wire:loading.attr="data-loading" wire:target="hide"><x-ui.icon name="eye-off" size="16" />{{ __('manager_customers.reviews.hide') }}</button>
                    @if($review['status'] !== 'flagged')
                        <button class="button button--secondary button--sm" type="button" wire:click="flag" wire:loading.attr="data-loading" wire:target="flag"><x-ui.icon name="flag" size="16" />{{ __('manager_customers.reviews.flag') }}</button>
                    @endif
                    <button class="button button--ghost button--sm" type="button" wire:click="stopModerating">{{ __('ui.actions.cancel') }}</button>
                </div>
            </div>
        @else
            <footer class="review-card__actions">
                @if($review['status'] === 'hidden')
                    <button class="button button--ghost button--sm" type="button" wire:click="unhide('{{ $review['id'] }}')" wire:confirm="{{ __('manager_customers.reviews.unhide_confirm') }}" data-confirm-title="{{ __('manager_customers.reviews.unhide') }}" data-confirm-label="{{ __('manager_customers.reviews.unhide') }}"><x-ui.icon name="eye" size="16" />{{ __('manager_customers.reviews.unhide') }}</button>
                @elseif($review['status'] === 'flagged')
                    {{-- A flagged review still counts; clearing the flag changes no average. --}}
                    <button class="button button--ghost button--sm" type="button" wire:click="unhide('{{ $review['id'] }}')" wire:loading.attr="data-loading" wire:target="unhide"><x-ui.icon name="flag" size="16" />{{ __('manager_customers.reviews.clear_flag') }}</button>
                @endif
                @if($review['status'] !== 'hidden')
                    <button class="button button--secondary button--sm" type="button" wire:click="moderate('{{ $review['id'] }}')"><x-ui.icon name="shield" size="16" />{{ __('manager_customers.reviews.moderate') }}</button>
                @endif
            </footer>
        @endif
    @endif
</article>
