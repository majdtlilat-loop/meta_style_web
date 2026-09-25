<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionScheduledChange;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The Manager's plan page and subscription banner, per center
|--------------------------------------------------------------------------
|
| Subscriptions live in the control database, so isolation is a WHERE on the
| BOUND tenant: a center's plan page and shell banner describe its own
| subscription — status, price as sold, trial, a scheduled change — and
| nothing of another center's, whatever the page is asked.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('shows each center its own subscription on the plan page and in the banner, never another center\'s', function (): void {
    $alpha = $this->registerCenter('Plan Alpha', 'owner@plan-alpha.test');
    $beta = $this->registerCenter('Plan Beta', 'owner@plan-beta.test');
    $alphaOwner = $this->ownerOf($alpha['tenant']);
    $betaOwner = $this->ownerOf($beta['tenant']);
    $alphaSlug = $alpha['registration']->requested_slug;
    $betaSlug = $beta['registration']->requested_slug;

    // Alpha: a trial with a price as sold and a move to Business agreed.
    Subscription::query()->where('tenant_id', $alpha['tenant']->id)->update([
        'status' => SubscriptionStatus::Trialing->value,
        'trial_ends_at' => CarbonImmutable::now()->addDays(4)->setTime(12, 0),
        'billing_period_snapshot' => 'monthly',
        'price_minor_snapshot' => 777000,
        'currency_snapshot' => 'IQD',
    ]);
    SubscriptionScheduledChange::query()->create([
        'subscription_id' => Subscription::query()->where('tenant_id', $alpha['tenant']->id)->value('id'),
        'target_plan_id' => Plan::query()->where('code', 'business')->value('id'),
        'effective_at' => CarbonImmutable::now()->addDays(15)->setTime(9, 0),
        'status' => 'scheduled',
        'reason' => 'Alpha only',
    ]);

    // Beta: active, yearly, a different price.
    Subscription::query()->where('tenant_id', $beta['tenant']->id)->update([
        'status' => SubscriptionStatus::Active->value,
        'trial_ends_at' => null,
        'current_period_end' => CarbonImmutable::now()->addMonths(6),
        'billing_period_snapshot' => 'yearly',
        'price_minor_snapshot' => 333000,
        'currency_snapshot' => 'IQD',
    ]);
    app(Entitlements::class)->invalidate($alpha['tenant']->id);
    app(Entitlements::class)->invalidate($beta['tenant']->id);

    $this->asCenter($beta['tenant'], function () use ($betaOwner, $betaSlug): void {
        $this->actingAs($betaOwner);

        $plan = $this->get("http://{$betaSlug}.localhost:8000/manager/plan?locale=en")->assertOk()->getContent();
        expect($plan)->toContain('333,000')
            ->toContain('Yearly')
            ->and(str_contains($plan, '777,000'))->toBeFalse()
            ->and(str_contains($plan, 'Moves to Business on'))->toBeFalse()
            ->and(str_contains($plan, __('manager_plan.summary.trial_ends')))->toBeFalse();

        // Beta is in good standing: no banner, and certainly not Alpha's trial.
        $page = $this->get("http://{$betaSlug}.localhost:8000/manager/notifications?locale=en")->assertOk()->getContent();
        expect(str_contains($page, 'subscription-banner'))->toBeFalse()
            ->and(str_contains($page, 'Your trial ends'))->toBeFalse();
    });

    $this->asCenter($alpha['tenant'], function () use ($alphaOwner, $alphaSlug): void {
        $this->actingAs($alphaOwner);

        $plan = $this->get("http://{$alphaSlug}.localhost:8000/manager/plan?locale=en")->assertOk()->getContent();
        expect($plan)->toContain('777,000')
            ->toContain('Moves to Business on')
            ->and(str_contains($plan, '333,000'))->toBeFalse();

        $this->get("http://{$alphaSlug}.localhost:8000/manager/notifications?locale=en")
            ->assertOk()
            ->assertSee('data-status="trialing"', false)
            ->assertSee('Your trial ends in 4 days', false);
    });

    // Nothing either center saw changed the other's subscription.
    expect((int) Subscription::query()->where('tenant_id', $beta['tenant']->id)->value('price_minor_snapshot'))->toBe(333000)
        ->and(SubscriptionScheduledChange::query()
            ->whereIn('subscription_id', Subscription::query()->where('tenant_id', $beta['tenant']->id)->select('id'))
            ->count())->toBe(0);
});
