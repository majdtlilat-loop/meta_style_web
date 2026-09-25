<?php

declare(strict_types=1);

use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Subscription;
use App\Livewire\Auth\RegisterCenter;
use App\Modules\LandingCms\Application\LandingPlans;
use App\Modules\LandingCms\Domain\LandingContent;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The corporate pricing table is the real plans
|--------------------------------------------------------------------------
|
| Prices, cycles, trials and features come from the plans at render time —
| the CMS holds only headings. A yearly saving is shown only when the yearly
| price really is below twelve monthly payments, and the cycle a visitor
| picks is the cycle their new center is billed on.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->saver = Plan::query()->create([
        'code' => 'landing_saver', 'name' => ['en' => 'Saver', 'ar' => 'الموفّر', 'ckb' => 'پاشەکەوتکەر'],
        'price_minor' => 10000, 'billing_period' => 'monthly', 'currency' => 'IQD',
        'monthly_price_minor' => 10000, 'yearly_price_minor' => 100000,
        'trial_days' => 10, 'is_public' => true, 'is_active' => true, 'is_featured' => true, 'sort_order' => 80,
    ]);
    $this->flat = Plan::query()->create([
        'code' => 'landing_flat', 'name' => ['en' => 'Flat', 'ar' => 'ثابت', 'ckb' => 'جێگیر'],
        'price_minor' => 20000, 'billing_period' => 'monthly', 'currency' => 'IQD',
        'monthly_price_minor' => 20000, 'yearly_price_minor' => 240000,
        'is_public' => true, 'is_active' => true, 'sort_order' => 81,
    ]);
    Plan::query()->create([
        'code' => 'landing_hidden', 'name' => ['en' => 'Internal only', 'ar' => 'داخلي', 'ckb' => 'ناوخۆیی'],
        'price_minor' => 5000, 'billing_period' => 'monthly', 'currency' => 'IQD', 'monthly_price_minor' => 5000,
        'is_public' => false, 'is_active' => true, 'sort_order' => 82,
    ]);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
    // Plans are reference data and are not truncated between tests.
    Schema::connection('control')->disableForeignKeyConstraints();
    DB::connection('control')->table('plans')->whereIn('code', ['landing_saver', 'landing_flat', 'landing_hidden'])->delete();
    Schema::connection('control')->enableForeignKeyConstraints();
});

it('shows a yearly saving only when it is real, and never lists a private plan', function (): void {
    $pricing = app(LandingPlans::class)->build('en');
    $byCode = collect($pricing['plans'])->keyBy('code');

    expect($byCode['landing_saver']['saving'])->toBe(16)
        ->and($byCode['landing_flat']['saving'])->toBeNull()
        ->and($byCode->has('landing_hidden'))->toBeFalse()
        ->and($pricing['cycles'])->toBe(['monthly' => true, 'yearly' => true])
        ->and($byCode['landing_saver']['register_url'])->toBe('/register?plan='.$this->saver->id);

    $this->get('http://localhost:8000/?locale=en')
        ->assertOk()
        ->assertSee(__('platform_landing.pricing.monthly'))
        ->assertSee(__('platform_landing.pricing.yearly'))
        ->assertSee(__('platform_landing.pricing.save_up_to', ['percent' => 16]))
        ->assertSee(__('platform_landing.pricing.save', ['percent' => 16]))
        ->assertSee('Saver')
        ->assertSee('Flat')
        ->assertDontSee('Internal only')
        ->assertSee(__('platform_landing.pricing.featured'));

    // The same table in Arabic and Kurdish, with the plans' own translations.
    $this->get('http://localhost:8000/?locale=ar')->assertOk()->assertSee('الموفّر')->assertSee(__('platform_landing.pricing.yearly', [], 'ar'));
    $this->get('http://localhost:8000/?locale=ckb')->assertOk()->assertSee('پاشەکەوتکەر')->assertDontSee('>CKB<', false);
});

it('hides the cycle toggle when every public plan is sold on one cycle only', function (): void {
    Plan::query()->whereIn('code', ['landing_saver', 'landing_flat'])->update(['yearly_price_minor' => null]);

    expect(app(LandingPlans::class)->build('en')['cycles'])->toBe(['monthly' => true, 'yearly' => false]);

    $this->get('http://localhost:8000/?locale=en')
        ->assertOk()
        ->assertDontSee('class="pricing-toggle"', false);
});

it('bills a new center on the cycle chosen on the pricing table', function (): void {
    Livewire::withQueryParams(['plan' => $this->saver->id, 'cycle' => 'yearly'])
        ->test(RegisterCenter::class)
        ->assertSet('planId', $this->saver->id)
        ->assertSet('billingCycle', 'yearly');

    $result = app(RegistrationService::class)->register([
        'center_name' => 'Yearly Salon', 'owner_name' => 'Owner', 'owner_email' => 'owner@yearly.test', 'owner_phone' => '+9647701234567',
        'password' => 'correct-horse-battery-staple', 'locale' => 'en', 'country' => 'IQ',
        'plan_id' => $this->saver->id, 'cycle' => 'yearly',
    ], 'test:yearly-salon');
    $registration = $result['registration'];
    expect($registration->options)->toBe(['cycle' => 'yearly']);

    $this->runProvisioning($registration);
    $this->trackRegistrationDatabase($registration);

    $subscription = Subscription::query()->where('tenant_id', $registration->refresh()->tenant_id)->firstOrFail();
    expect($subscription->billing_period_snapshot)->toBe('yearly')
        ->and((int) $subscription->price_minor_snapshot)->toBe(100000)
        // Choosing a cycle never skips the trial: only a Super Admin can do that.
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing);
});

it('keeps the default landing copy translated even for revisions saved before translations existed', function (): void {
    $content = LandingContent::hydrate(['hero' => ['title' => ['en' => 'Run the center. Grow the experience.', 'ar' => '', 'ckb' => ''], 'body' => ['en' => 'A sentence someone wrote.', 'ar' => '', 'ckb' => '']]]);

    expect($content['hero']['title']['ar'])->toBe('أدِر المركز. وطوّر التجربة.')
        ->and($content['hero']['title']['ckb'])->not->toBe('')
        // Text a person wrote is never "translated" for them.
        ->and($content['hero']['body']['ar'])->toBe('');
});
