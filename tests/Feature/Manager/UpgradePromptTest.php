<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlanEntitlement;
use App\Livewire\Center\Shell\UpgradePrompt;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The upgrade prompt behind a locked sidebar item
|--------------------------------------------------------------------------
|
| The feature key comes from the page, so it is validated on the server:
| catalog + the navigation's own feature map + this person's permissions +
| "actually locked". Prices are the plan's own, in the plan's currency, and
| "Recommended" appears only on a plan configured featured.
|
*/

/** A public plan that sells finance, in US dollars, for the length of one test. */
function shellPromptPlan(bool $featured = false): Plan
{
    /** @var Plan $plan */
    $plan = Plan::query()->create([
        'code' => 'shell-test-growth',
        'name' => ['en' => 'Growth Test', 'ar' => 'نمو تجريبي', 'ckb' => 'گەشەی تاقیکاری'],
        'price_minor' => 2900,
        'monthly_price_minor' => 2900,
        'yearly_price_minor' => 29000,
        'currency' => 'USD',
        'billing_period' => 'monthly',
        'trial_days' => null,
        'is_public' => true,
        'is_active' => true,
        'is_featured' => $featured,
        'sort_order' => 1,
    ]);
    $plan->syncEntitlements(['booking', 'customer_accounts', 'pos', 'finance']);

    return $plan;
}

afterEach(function (): void {
    // Plans are reference data the harness keeps between tests: leave none behind.
    $ids = Plan::query()->where('code', 'like', 'shell-test-%')->pluck('id');
    PlanEntitlement::query()->whereIn('plan_id', $ids)->delete();
    Plan::query()->whereIn('id', $ids)->delete();

    $this->tearDownRegisteredCenters();
});

it('opens for a locked feature with the plans that include it, priced in each plan\'s own currency', function (): void {
    $center = $this->registerCenter('Prompt Center', 'owner@prompt-center.test');
    $owner = $this->ownerOf($center['tenant']);
    shellPromptPlan();

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('en');
        $currencies = app(PlatformCurrencies::class);

        Livewire::actingAs($owner)
            ->test(UpgradePrompt::class)
            ->assertDontSee('class="feature-lock"', false)
            ->dispatch('open-upgrade', feature: 'finance')
            ->assertSet('feature', 'finance')
            ->assertSee('role="dialog"', false)
            ->assertSee(__('platform_labels.entitlement.finance'))
            // Every public plan that includes it, in commercial order…
            ->assertSeeInOrder(['Growth Test', 'Business'])
            // …in its own currency, and the seeded Business plan in IQD.
            ->assertSee($currencies->format(2900, 'USD', 'en'))
            ->assertSee($currencies->format(29000, 'USD', 'en'))
            ->assertSee($currencies->format(150000, 'IQD', 'en'))
            ->assertSee(__('manager_features.ui.save', ['percent' => 16]))
            // Nothing is configured featured, so nothing is "recommended".
            ->assertDontSee(__('manager_features.ui.recommended'))
            // Straight to the comparison, feature highlighted.
            ->assertSee('/manager/plan?feature=finance#compare', false)
            ->call('close')
            ->assertSet('feature', null);
    });
});

it('calls a plan recommended only when it is configured featured', function (): void {
    $center = $this->registerCenter('Prompt Featured', 'owner@prompt-featured.test');
    $owner = $this->ownerOf($center['tenant']);
    shellPromptPlan(featured: true);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('en');

        Livewire::actingAs($owner)
            ->test(UpgradePrompt::class)
            ->call('open', 'finance')
            ->assertSet('feature', 'finance')
            ->assertSee(__('manager_features.ui.recommended'));
    });
});

it('refuses keys it must not open: unknown, outside the navigation, owned, or without the permission', function (): void {
    $center = $this->registerCenter('Prompt Refusals', 'owner@prompt-refusals.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $prompt = Livewire::actingAs($owner)->test(UpgradePrompt::class);

        foreach (['', 'nope', 'manager_features.ui', 'white_label_app', 'booking'] as $key) {
            $prompt->call('open', $key)->assertSet('feature', null);
        }

        // A cashier cannot read finance, so there is nothing to sell them.
        $cashier = $this->seedStaffMember(SystemRole::Cashier, name: 'Cashier Dara');
        Livewire::actingAs($cashier)
            ->test(UpgradePrompt::class)
            ->call('open', 'finance')
            ->assertSet('feature', null)
            // …but loyalty is theirs to read, and the center lacks it.
            ->call('open', 'loyalty')
            ->assertSet('feature', 'loyalty')
            ->assertSee(__('manager_features.ui.contact_body', ['feature' => __('platform_labels.entitlement.loyalty')]))
            // The Plan page and platform support are not theirs to open: no
            // dead-end link, a pointer to who can act instead.
            ->assertDontSee('/manager/plan?feature=loyalty', false)
            ->assertSee(__('manager_shell.upgrade.ask_manager'));
    });
});
