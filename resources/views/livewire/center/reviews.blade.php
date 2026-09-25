{{--
    Reviews: what customers said about their visits.

    docs/22-REVIEWS.md §§18, 23, 24, 56. A summary for the chosen branch and
    days, filters, the list and the moderation actions. Hiding takes a reason;
    the customer's words are never editable, and every value here is escaped by
    Blade because a comment is untrusted text.
--}}
<div class="stack reviews-page">
    <x-ui.page-header :title="__('ui.manager_nav.items.reviews')" />

    @if($offer)
        <x-manager.feature-locked :offer="$offer" compact history />
    @endif

    @if($error !== '')
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="alert-circle" /><p>{{ $error }}</p></div>
    @endif
    @if($saved !== '')
        <div class="notice" data-tone="success" role="status"><x-ui.icon name="check-circle" /><p>{{ $saved }}</p></div>
    @endif

    <form class="filter-bar" role="search" x-on:submit.prevent aria-label="{{ __('ui.actions.filters') }}" data-collapsible x-data="{ open: false }" :class="{ 'is-open': open }">
        <div class="field filter-bar__search">
            <label for="review-branch">{{ __('ui.fields.branch') }}</label>
            <select id="review-branch" wire:model.live="branch">
                <option value="">{{ __('ui.fields.all_branches') }}</option>
                @foreach($branches as $option)
                    <option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </div>
        <button class="button button--secondary filter-bar__toggle" type="button" x-on:click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="review-from">
            <x-ui.icon name="filter" size="16" />{{ __('ui.actions.filters') }}
        </button>
        <div class="field">
            <label for="review-from">{{ __('ui.fields.from') }}</label>
            <input id="review-from" type="date" wire:model.live="from" max="{{ $to ?: $today }}">
        </div>
        <div class="field">
            <label for="review-to">{{ __('ui.fields.to') }}</label>
            <input id="review-to" type="date" wire:model.live="to" min="{{ $from }}" max="{{ $today }}">
        </div>
        <div class="field">
            <label for="review-status">{{ __('ui.fields.status') }}</label>
            <select id="review-status" wire:model.live="status">
                <option value="">{{ __('ui.fields.all') }}</option>
                @foreach($statuses as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="review-rating">{{ __('manager_customers.reviews.rating') }}</label>
            <select id="review-rating" wire:model.live="rating">
                <option value="">{{ __('ui.fields.all') }}</option>
                @foreach([5, 4, 3, 2, 1] as $n)<option value="{{ $n }}">{{ $n }} ★</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="review-service">{{ __('manager_customers.reviews.service') }}</label>
            <select id="review-service" wire:model.live="service">
                <option value="">{{ __('ui.fields.all') }}</option>
                @foreach($services as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="review-employee">{{ __('manager_customers.reviews.employee') }}</label>
            <select id="review-employee" wire:model.live="employee">
                <option value="">{{ __('ui.fields.all') }}</option>
                @foreach($employees as $option)<option value="{{ $option['uuid'] }}">{{ $option['name'] }}</option>@endforeach
            </select>
        </div>
        @if($hasFilters)
            <div class="filter-bar__actions">
                <button class="button button--ghost button--sm" type="button" wire:click="clearFilters"><x-ui.icon name="close" size="16" />{{ __('ui.actions.clear_filters') }}</button>
            </div>
        @endif
    </form>

    <livewire:center.reviews.summary :branch="$branch" :from="$from" :to="$to" :key="'review-summary-'.$summaryKey" />

    <section class="stack" aria-label="{{ __('manager_customers.reviews.list') }}">
        <div class="review-list" wire:loading.class="is-refreshing" wire:target="status,rating,from,to,branch,service,employee,clearFilters,older,newer">
            @forelse($reviews as $review)
                @include('livewire.center.reviews.card', ['review' => $review, 'canModerate' => $canModerate, 'acting' => $acting])
            @empty
                <x-ui.card>
                    @if($hasFilters)
                        <x-ui.empty-state compact icon="filter" :title="__('manager_customers.reviews.no_match')">
                            <button class="button button--secondary button--sm" type="button" wire:click="clearFilters">{{ __('ui.actions.clear_filters') }}</button>
                        </x-ui.empty-state>
                    @else
                        <x-ui.empty-state compact icon="reviews" :title="__('manager_customers.reviews.none')" />
                    @endif
                </x-ui.card>
            @endforelse
        </div>

        @if($hasNewer || $olderCursor)
            <nav class="cluster cluster--between reviews-pager" aria-label="{{ __('ui.pagination.label') }}">
                <button class="button button--secondary button--sm" type="button" wire:click="newer" @disabled(! $hasNewer)><x-ui.icon name="chevron-left" size="16" />{{ __('manager_customers.reviews.newer') }}</button>
                <button class="button button--secondary button--sm" type="button" @if($olderCursor) wire:click="older('{{ $olderCursor }}')" @endif @disabled(! $olderCursor)>{{ __('manager_customers.reviews.older') }}<x-ui.icon name="chevron-right" size="16" /></button>
            </nav>
        @endif
    </section>
</div>
