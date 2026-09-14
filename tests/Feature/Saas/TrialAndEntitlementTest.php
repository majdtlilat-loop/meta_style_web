<?php

declare(strict_types=1);

use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Entitlements\Exceptions\InvalidEntitlementCatalog;
use App\Kernel\Entitlements\TenantAccessLevel;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\SaaS\TrialPolicy;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Config\Repository;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Trials and entitlements
|--------------------------------------------------------------------------
|
| docs/05-ENTITLEMENTS.md, docs/13-ROADMAP.md Phase 3 Parts A and B.
|
*/

function catalogWith(array $entitlements): EntitlementCatalog
{
    return new EntitlementCatalog(new Repository(['entitlements' => ['entitlements' => $entitlements]]));
}

// ---------------------------------------------------------------- trials ---

it('applies the platform default trial length', function (): void {
    PlatformSetting::put(PlatformSetting::DEFAULT_TRIAL_DAYS, 21);

    $result = $this->registerCenter();

    $subscription = Subscription::query()->where('tenant_id', $result['tenant']->id)->firstOrFail();

    expect($subscription->trial_starts_at->diffInDays($subscription->trial_ends_at))->toBe(21.0);
});

it('lets a tenant override the trial length', function (): void {
    PlatformSetting::put(PlatformSetting::DEFAULT_TRIAL_DAYS, 14);

    $result = $this->registerCenter();
    $tenant = TenantModel::query()->findOrFail($result['tenant']->id);

    $tenant->forceFill(['trial_days_override' => 45])->save();

    $subscription = app(TrialPolicy::class)->start($tenant, Subscription::query()->firstOrFail()->plan);

    expect($subscription->trial_starts_at->diffInDays($subscription->trial_ends_at))->toBe(45.0);
});

it('reads the trial length from the platform setting, never a hardcoded number', function (): void {
    PlatformSetting::put(PlatformSetting::DEFAULT_TRIAL_DAYS, 7);

    expect(app(TrialPolicy::class)->defaultDays())->toBe(7);

    PlatformSetting::put(PlatformSetting::DEFAULT_TRIAL_DAYS, 30);

    expect(app(TrialPolicy::class)->defaultDays())->toBe(30);
});

it('enforces trial expiry server-side, without waiting for a sweep', function (): void {
    $result = $this->registerCenter();

    $subscription = Subscription::query()->where('tenant_id', $result['tenant']->id)->firstOrFail();
    $subscription->forceFill(['trial_ends_at' => Carbon::now()->subMinute()])->save();

    // The stored status still says "trialing" — nothing has run to change it.
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Trialing)
        // ...but it is already treated as expired, which is what stops a lapsed
        // trial being usable for however long the sweep takes to notice.
        ->and($subscription->trialHasExpired())->toBeTrue()
        ->and($subscription->effectiveStatus())->toBe(SubscriptionStatus::Expired)
        ->and($subscription->grantsAccess())->toBeFalse();
});

it('expires lapsed trials when the sweep runs', function (): void {
    $result = $this->registerCenter();

    Subscription::query()->where('tenant_id', $result['tenant']->id)
        ->update(['trial_ends_at' => Carbon::now()->subDay()]);

    expect(app(TrialPolicy::class)->expireLapsedTrials())->toBe(1)
        ->and(Subscription::query()->firstOrFail()->status)->toBe(SubscriptionStatus::Expired);
});

// ---------------------------------------------------------- entitlements ---

it('seeds exactly the plan matrix, and no phase quietly extends it', function (): void {
    // WHAT each plan sells, pinned. A phase that ships a capability defines the
    // capability; it does not get to decide which package receives it, and the
    // two are separate decisions made by separate people at separate times.
    //
    // Pinned as an EXACT set rather than a `toContain`, because the failure
    // this catches is an addition, not a removal. Phase 8 made exactly that
    // mistake: it put the three queue keys into trial and business, which broke
    // four tests here loudly and — much worse — made a fifth pass while
    // asserting nothing, because granting a capability the plan already grants
    // proves nothing at all.
    $matrix = [];

    foreach (Plan::query()->get() as $plan) {
        $codes = $plan->entitlementCodes();
        sort($codes);

        $matrix[$plan->code] = $codes;
    }

    // Sorted both ways, so the assertion is about CONTENT: a plan's display
    // order is packaging too, and it must not be able to fail this test.
    ksort($matrix);

    expect($matrix)->toBe([
        'business' => ['booking', 'crm', 'customer_accounts', 'finance', 'payments', 'pos', 'printing'],
        'starter' => ['booking', 'customer_accounts'],
        'trial' => ['booking', 'crm', 'customer_accounts', 'pos'],
    ]);

    // And the queue specifically: in the CATALOG, in no package. A center that
    // wants it buys it as an add-on, which is a per-tenant override
    // (`Tests\Support\SeedsQueue::grantQueueEntitlements`).
    foreach (['queue_management', 'queue_display', 'queue_voice'] as $key) {
        expect(app(EntitlementCatalog::class)->keys())->toContain($key);

        foreach ($matrix as $plan => $codes) {
            // `toContain` takes needles, not a message: a second argument would
            // be a second needle, and the negation would pass on it alone.
            expect(in_array($key, $codes, true))->toBeFalse("{$plan} must not sell {$key}");
        }
    }
});

it('grants what the plan grants', function (): void {
    $result = $this->registerCenter();

    $effective = app(Entitlements::class)->for($result['tenant']->id);

    // The seeded trial plan.
    //
    // `queue_management` is the stand-in for "something this plan does not
    // sell" throughout this file, and the test above is what keeps it that
    // way: put the queue in a plan and the matrix fails LOUDLY rather than
    // these assertions inverting in silence.
    expect($effective->owned)->toContain('booking', 'customer_accounts', 'crm', 'pos')
        ->and($effective->enabled('booking'))->toBeTrue()
        ->and($effective->enabled('queue_management'))->toBeFalse();
});

it('honours a tenant enable override', function (): void {
    $result = $this->registerCenter();

    TenantEntitlementOverride::query()->create([
        'tenant_id' => $result['tenant']->id,
        'entitlement' => 'queue_management',
        'mode' => OverrideMode::Grant,
        'reason' => 'sold as an add-on',
    ]);

    app(Entitlements::class)->invalidate($result['tenant']->id);

    expect(app(Entitlements::class)->for($result['tenant']->id)->enabled('queue_management'))->toBeTrue();
});

it('honours a tenant disable override, which beats the plan', function (): void {
    $result = $this->registerCenter();

    TenantEntitlementOverride::query()->create([
        'tenant_id' => $result['tenant']->id,
        'entitlement' => 'pos',
        'mode' => OverrideMode::Revoke,
        'reason' => 'abuse',
    ]);

    app(Entitlements::class)->invalidate($result['tenant']->id);

    // Revoke must beat a plan grant, or removing a capability from one center
    // would mean editing the plan every other center is on.
    expect(app(Entitlements::class)->for($result['tenant']->id)->enabled('pos'))->toBeFalse();
});

it('ignores an override outside its validity window', function (): void {
    $result = $this->registerCenter();

    TenantEntitlementOverride::query()->create([
        'tenant_id' => $result['tenant']->id,
        'entitlement' => 'queue_management',
        'mode' => OverrideMode::Grant,
        'expires_at' => Carbon::now()->subHour(),
    ]);

    app(Entitlements::class)->invalidate($result['tenant']->id);

    // A time-boxed courtesy that silently outlives its window is how a support
    // gesture becomes a permanent free feature.
    expect(app(Entitlements::class)->for($result['tenant']->id)->enabled('queue_management'))->toBeFalse();
});

it('drops an entitlement whose dependency is unmet', function (): void {
    $catalog = catalogWith([
        'queue_management' => ['category' => 'queue'],
        'queue_voice' => ['category' => 'queue', 'requires' => ['queue_management']],
    ]);

    expect($catalog->applyDependencies(['queue_voice']))->toBe([])
        ->and($catalog->applyDependencies(['queue_management', 'queue_voice']))
        ->toBe(['queue_management', 'queue_voice']);
});

it('applies dependency closure transitively', function (): void {
    $catalog = catalogWith([
        'a' => [],
        'b' => ['requires' => ['a']],
        'c' => ['requires' => ['b']],
    ]);

    // Dropping `a` must also drop `b`, and therefore `c`.
    expect($catalog->applyDependencies(['b', 'c']))->toBe([])
        ->and($catalog->applyDependencies(['a', 'b', 'c']))->toBe(['a', 'b', 'c']);
});

it('rejects a dependency cycle', function (): void {
    catalogWith([
        'a' => ['requires' => ['b']],
        'b' => ['requires' => ['a']],
    ])->keys();
})->throws(InvalidEntitlementCatalog::class, 'cycle');

it('rejects a dependency on a key that does not exist', function (): void {
    catalogWith(['a' => ['requires' => ['nope']]])->keys();
})->throws(InvalidEntitlementCatalog::class);

it('fails ensure() for a capability the tenant does not own', function (): void {
    $result = $this->registerCenter();

    $this->asCenter($result['tenant'], function (): void {
        app(Entitlements::class)->ensure('queue_management');
    });
})->throws(EntitlementRequired::class);

it('passes ensure() for a capability the tenant owns', function (): void {
    $result = $this->registerCenter();

    $this->asCenter($result['tenant'], function (): void {
        app(Entitlements::class)->ensure('booking');
    });

    expect(true)->toBeTrue();
});

it('rejects ensure() for a key that is not in the catalog at all', function (): void {
    $result = $this->registerCenter();

    $this->asCenter($result['tenant'], function (): void {
        app(Entitlements::class)->ensure('not_a_real_entitlement');
    });
})->throws(InvalidEntitlementCatalog::class);

it('invalidates the cache when entitlements change', function (): void {
    $result = $this->registerCenter();
    $entitlements = app(Entitlements::class);

    expect($entitlements->for($result['tenant']->id)->enabled('queue_management'))->toBeFalse();

    $before = (int) TenantModel::query()->whereKey($result['tenant']->id)->value('entitlements_version');

    TenantEntitlementOverride::query()->create([
        'tenant_id' => $result['tenant']->id,
        'entitlement' => 'queue_management',
        'mode' => OverrideMode::Grant,
    ]);

    $entitlements->invalidate($result['tenant']->id);

    $after = (int) TenantModel::query()->whereKey($result['tenant']->id)->value('entitlements_version');

    // Invalidation is a version bump, so stale keys become unreachable rather
    // than needing to be swept.
    expect($after)->toBe($before + 1)
        ->and(app(Entitlements::class)->for($result['tenant']->id)->enabled('queue_management'))->toBeTrue();
});

it('keeps ownership while withdrawing use when a subscription lapses', function (): void {
    $result = $this->registerCenter();

    Subscription::query()->where('tenant_id', $result['tenant']->id)
        ->update(['status' => SubscriptionStatus::Suspended->value]);

    app(Entitlements::class)->invalidate($result['tenant']->id);

    $effective = app(Entitlements::class)->for($result['tenant']->id);

    // A suspended center still OWNS what it pays for — otherwise reinstatement
    // would be a re-purchase — but may not use it.
    expect($effective->owns('pos'))->toBeTrue()
        ->and($effective->enabled('pos'))->toBeFalse()
        ->and($effective->usable())->toBe([])
        ->and($effective->accessLevel)->toBe(TenantAccessLevel::ReadOnly);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
