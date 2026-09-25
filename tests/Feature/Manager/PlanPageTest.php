<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use App\Livewire\Center\Plan as PlanPage;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Plan page (center.plan)
|--------------------------------------------------------------------------
|
| Read-only, `settings.view` like Usage. Everything shown comes from the
| subscription's own snapshot and the plan catalog; the only action is to ask
| Meta Style through support.
|
*/

it('shows the trial with its days left, the cycle and price as sold, and a scheduled change', function (): void {
    $center = $this->registerCenter('Plan Center', 'owner@plan-center.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;
    $tenantId = $center['tenant']->id;

    $ends = CarbonImmutable::now()->addDays(9)->setTime(12, 0);
    Subscription::query()->where('tenant_id', $tenantId)->update([
        'status' => SubscriptionStatus::Trialing->value,
        'trial_ends_at' => $ends,
        'billing_period_snapshot' => 'monthly',
        'price_minor_snapshot' => 50000,
        'currency_snapshot' => 'IQD',
    ]);
    $subscription = Subscription::query()->where('tenant_id', $tenantId)->firstOrFail();
    $business = Plan::query()->where('code', 'business')->firstOrFail();
    SubscriptionScheduledChange::query()->create([
        'subscription_id' => $subscription->getKey(),
        'target_plan_id' => $business->getKey(),
        'effective_at' => CarbonImmutable::now()->addDays(20)->setTime(9, 0),
        'status' => 'scheduled',
        'reason' => 'Agreed with the center',
    ]);
    app(Entitlements::class)->invalidate($tenantId);

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $ends): void {
        $this->actingAs($owner);

        $this->get("http://{$slug}.localhost:8000/manager/plan?locale=en")
            ->assertOk()
            ->assertSee('<h1>Plan &amp; billing</h1>', false)
            ->assertSee('Trial')
            ->assertSee('Monthly')
            ->assertSee('50,000')
            ->assertSee(__('manager_plan.summary.trial_ends'))
            ->assertSee($ends->locale('en')->translatedFormat('j F Y'))
            ->assertSee('9 days left')
            ->assertSee('Moves to Business on')
            // The comparison lists the public plans, never the private trial as an offer.
            ->assertSee('id="compare"', false)
            ->assertSeeInOrder(['Starter', 'Business'])
            ->assertSee(__('manager_plan.compare.your_plan'))
            // No Super Admin billing controls on a center's page.
            ->assertDontSee('wire:click="changePlan', false);
    });
});

it('highlights the feature a locked item pointed at, and switches the cycle', function (): void {
    $center = $this->registerCenter('Plan Highlight', 'owner@plan-highlight.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('en');

        Livewire::withQueryParams(['feature' => 'finance'])
            ->actingAs($owner)
            ->test(PlanPage::class)
            ->assertSet('feature', 'finance')
            ->assertSee('data-plan-highlight', false)
            ->assertSee(__('manager_plan.compare.focus_offered', ['feature' => 'Finance']))
            ->call('setCycle', 'yearly')
            ->assertSet('cycle', 'yearly')
            ->call('setCycle', 'weekly')
            ->assertSet('cycle', 'monthly');

        // An unknown key highlights nothing.
        Livewire::withQueryParams(['feature' => 'not-a-feature'])
            ->actingAs($owner)
            ->test(PlanPage::class)
            ->assertDontSee('data-plan-highlight', false);
    });
});

it('is a settings.view page: anybody else gets a clear refusal and no plan data', function (): void {
    $center = $this->registerCenter('Plan Gate', 'owner@plan-gate.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $cashier = $this->seedStaffMember(SystemRole::Cashier, name: 'Cashier Rezan');
        $this->actingAs($cashier);

        $this->get("http://{$slug}.localhost:8000/manager/plan?locale=en")
            ->assertOk()
            ->assertSee(__('manager_plan.not_allowed'))
            ->assertDontSee('id="compare"', false)
            ->assertDontSee(__('manager_plan.summary.current'));
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
