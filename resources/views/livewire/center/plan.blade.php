{{--
    The center's plan (read-only). Everything is computed by
    App\View\Manager\PlanOverview; plan changes are Meta Style's, so the only
    actions here lead to platform support.
--}}
<div class="stack plan-page">
    <x-ui.page-header :title="__('manager_plan.title')">
        @if($allowed && $plan['links']['support'])
            <x-slot:actions>
                <a class="button button--secondary" href="{{ $plan['links']['support'] }}" wire:navigate><x-ui.icon name="support" size="16" />{{ __('manager_plan.actions.contact') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if(! $allowed)
        <div class="notice" data-tone="danger" role="alert"><x-ui.icon name="lock" /><p>{{ __('manager_plan.not_allowed') }}</p></div>
    @elseif($plan['summary'] === null)
        <x-ui.card>
            <x-ui.empty-state icon="plans" :title="__('manager_plan.empty.title')" :description="__('manager_plan.empty.description')" />
        </x-ui.card>
    @else
        @include('livewire.center.plan.summary', ['summary' => $plan['summary'], 'allowances' => $plan['allowances'], 'usageLink' => $plan['links']['usage']])
        @include('livewire.center.plan.features', ['features' => $plan['features']])
        @include('livewire.center.plan.compare', ['compare' => $plan['compare']])
    @endif
</div>
