{{-- The subscription at a glance, and the allowances that come with it. --}}
<div class="plan-overview">
    <section class="card plan-hero" aria-labelledby="plan-current-title">
        <div class="plan-hero__head">
            <span class="plan-hero__icon" aria-hidden="true"><x-ui.icon name="plans" /></span>
            <div class="plan-hero__identity">
                <p class="eyebrow">{{ __('manager_plan.summary.current') }}</p>
                <h2 id="plan-current-title">{{ $summary['name'] }}</h2>
                <div class="cluster cluster--tight">
                    <x-ui.status :value="$summary['status']" :label="$summary['status_label']" />
                    @if($summary['cycle_label'])<span class="badge">{{ $summary['cycle_label'] }}</span>@endif
                </div>
            </div>
            <div class="plan-hero__price">
                @if($summary['free'])
                    <strong>{{ __('manager_plan.summary.free') }}</strong>
                @elseif($summary['price'])
                    <strong class="tabular">{{ $summary['price'] }}</strong>
                    @if($summary['cycle_label'])<span>{{ $summary['cycle_label'] }}</span>@endif
                @endif
            </div>
        </div>

        @if($summary['description'])
            <p class="plan-hero__description">{{ $summary['description'] }}</p>
        @endif

        @if($summary['read_only'])
            <div class="notice" data-tone="danger" role="status"><x-ui.icon name="lock" /><p>{{ __('manager_plan.summary.read_only') }}</p></div>
        @endif

        <dl class="plan-facts">
            @if($summary['trial'] && $summary['trial_ends'])
                <div>
                    <dt>{{ __('manager_plan.summary.trial_ends') }}</dt>
                    <dd>{{ $summary['trial_ends'] }}
                        @if($summary['trial_days_left'] !== null)<span class="plan-facts__hint">{{ trans_choice('manager_plan.summary.days_left', $summary['trial_days_left'], ['count' => $summary['trial_days_left']]) }}</span>@endif
                    </dd>
                </div>
            @endif
            @if($summary['renews'])
                <div><dt>{{ __('manager_plan.summary.renews') }}</dt><dd>{{ $summary['renews'] }}</dd></div>
            @endif
            @if($summary['grace_ends'])
                <div><dt>{{ __('manager_plan.summary.grace_ends') }}</dt><dd>{{ $summary['grace_ends'] }}</dd></div>
            @endif
            @if($summary['period_ends'])
                <div><dt>{{ __('manager_plan.summary.period_ended') }}</dt><dd>{{ $summary['period_ends'] }}</dd></div>
            @endif
            @if($summary['scheduled'])
                <div class="plan-facts__change">
                    <dt>{{ __('manager_plan.summary.scheduled') }}</dt>
                    <dd>{{ $summary['scheduled']['at'] ? __('manager_plan.summary.scheduled_to_on', ['plan' => $summary['scheduled']['plan'], 'date' => $summary['scheduled']['at']]) : __('manager_plan.summary.scheduled_to', ['plan' => $summary['scheduled']['plan']]) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    <x-ui.card :title="__('manager_plan.allowances.title')">
        @if($usageLink)
            <x-slot:actions>
                <a class="button button--ghost button--sm" href="{{ $usageLink }}" wire:navigate>{{ __('manager_plan.allowances.view_usage') }}<x-ui.icon name="arrow-right" size="14" /></a>
            </x-slot:actions>
        @endif
        @if($allowances === [])
            <x-ui.empty-state compact icon="usage" :title="__('manager_plan.allowances.none')" />
        @else
            <dl class="summary-list">
                @foreach($allowances as $allowance)
                    <div>
                        <dt>{{ $allowance['label'] }}<span class="plan-facts__hint">{{ $allowance['source'] }}</span></dt>
                        <dd class="tabular">{{ $allowance['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </x-ui.card>
</div>
