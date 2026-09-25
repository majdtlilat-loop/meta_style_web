<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Manager shell: navigation, locks, banner
|--------------------------------------------------------------------------
|
| Permission hides; entitlement locks. A lock names the REAL plan that sells
| the feature (the seeded catalog: Business includes finance, no public plan
| includes the queue), never a hardcoded badge. A suspended center gets one
| banner and no locks. Locked items are plain links to the plan page — never
| wire:navigate — so without JavaScript they still land somewhere useful.
|
*/

function shellNavStatus(string $tenantId, SubscriptionStatus $status, array $extra = []): void
{
    Subscription::query()->where('tenant_id', $tenantId)->update(['status' => $status->value] + $extra);
    app(Entitlements::class)->invalidate($tenantId);
}

/** The opening tag of the sidebar link for one locked feature, or null. */
function shellNavLockedAnchor(string $html, string $feature): ?string
{
    return preg_match('/<a [^>]*data-upgrade-feature="'.preg_quote($feature, '/').'"[^>]*>/', $html, $match) === 1 ? $match[0] : null;
}

it('groups the sidebar by work and locks what the plan lacks with the real plan name', function (): void {
    $center = $this->registerCenter('Nav Center', 'owner@nav-center.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);
        $html = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en")->assertOk()->getContent();

        // One navigation, grouped by the work.
        expect(substr_count($html, 'aria-label="Center navigation"'))->toBe(1);
        foreach (['Operations', 'Customers', 'Center', 'Finance', 'Analytics', 'Communication', 'Settings'] as $group) {
            expect($html)->toContain('<span class="app-nav__label" aria-hidden="true">'.$group.'</span>');
        }

        // The trial plan includes booking: an ordinary link.
        expect(shellNavLockedAnchor($html, 'booking'))->toBeNull()
            ->and($html)->toContain('data-tooltip="Bookings"');

        // Finance is sold by the seeded Business plan.
        $finance = shellNavLockedAnchor($html, 'finance');
        expect($finance)->not->toBeNull()
            ->and($finance)->toContain('href="http://'.$slug.'.localhost:8000/manager/plan?feature=finance"')
            ->and($finance)->toContain('data-tooltip="Finance · Available from Business"')
            ->and($finance)->toContain('data-locked')
            ->and(str_contains((string) $finance, 'wire:navigate'))->toBeFalse();

        // No public plan sells the queue: the item says so instead of naming one.
        expect(shellNavLockedAnchor($html, 'queue_management'))->toContain('data-tooltip="Queue · Contact us"');

        // Conversations are locked on WhatsApp OR the assistant — neither is owned.
        expect(shellNavLockedAnchor($html, 'whatsapp_booking') ?? shellNavLockedAnchor($html, 'rayan_ai'))->not->toBeNull();

        // No hardcoded plan badge anywhere in the shell.
        expect(str_contains($html, '>PRO<'))->toBeFalse()
            ->and(str_contains($html, 'badge--pro'))->toBeFalse();

        // The account menu: the person's own address and their role, from PHP.
        expect($html)->toContain('owner@nav-center.test')
            ->toContain('>Owner</span>')
            ->toContain('href="http://'.$slug.'.localhost:8000/manager/plan"');
    });
});

it('never advertises a module to somebody without its permission', function (): void {
    $center = $this->registerCenter('Nav Cashier', 'owner@nav-cashier.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $cashier = $this->seedStaffMember(SystemRole::Cashier, name: 'Cashier Lana');
        $this->actingAs($cashier);

        $html = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en")->assertOk()->getContent();

        // No finance permission: no finance item and no finance upsell.
        expect(shellNavLockedAnchor($html, 'finance'))->toBeNull()
            ->and(str_contains($html, 'data-tooltip="Finance'))->toBeFalse()
            // Loyalty is readable to a cashier, so its lock is shown to them.
            ->and(shellNavLockedAnchor($html, 'loyalty'))->not->toBeNull()
            // Plan and settings are commercial: not in a cashier's menu or banner.
            ->and(str_contains($html, '/manager/plan"'))->toBeFalse()
            ->and(str_contains($html, 'subscription-banner'))->toBeFalse();
    });
});

it('shows one subscription banner and no locks while the center is suspended', function (): void {
    $center = $this->registerCenter('Nav Suspended', 'owner@nav-suspended.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    shellNavStatus($center['tenant']->id, SubscriptionStatus::Suspended);

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);
        $html = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en")->assertOk()->getContent();

        expect(substr_count($html, 'class="subscription-banner"'))->toBe(1)
            ->and($html)->toContain('data-status="suspended"')
            ->toContain(__('manager_shell.banner.suspended'))
            ->and(str_contains($html, 'data-locked'))->toBeFalse()
            ->and(str_contains($html, 'Available from'))->toBeFalse();
    });
});

it('tells the people who manage the plan how many trial days are left, and when a payment is late', function (): void {
    $center = $this->registerCenter('Nav Trial', 'owner@nav-trial.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;
    $tenantId = $center['tenant']->id;

    shellNavStatus($tenantId, SubscriptionStatus::Trialing, ['trial_ends_at' => now()->addDays(5)->setTime(12, 0)]);

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $tenantId): void {
        $this->actingAs($owner);
        $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en")
            ->assertOk()
            ->assertSee('data-status="trialing"', false)
            ->assertSee('Your trial ends in 5 days', false)
            ->assertDontSee('Needs attention');

        shellNavStatus($tenantId, SubscriptionStatus::PastDue, ['grace_ends_at' => now()->addDays(3)->setTime(12, 0)]);

        $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en")
            ->assertOk()
            ->assertSee('data-status="past_due"', false)
            ->assertSee('Access continues until', false);

        // An active subscription is not news.
        shellNavStatus($tenantId, SubscriptionStatus::Active, ['current_period_end' => now()->addMonth()]);
        $html = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en")->assertOk()->getContent();
        expect(str_contains($html, 'subscription-banner'))->toBeFalse();
    });
});

it('renders the shell right-to-left for Arabic and Kurdish, and labels Kurdish KU', function (): void {
    $center = $this->registerCenter('Nav Rtl', 'owner@nav-rtl.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);

        $arabic = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=ar")->assertOk()->getContent();
        expect($arabic)->toContain('<html lang="ar" dir="rtl"')
            ->toContain('>العمليات</span>')
            ->toContain('متاح من باقة');

        $kurdish = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=ckb")->assertOk()->getContent();
        expect($kurdish)->toContain('<html lang="ckb" dir="rtl"')
            ->and(str_contains($kurdish, '>CKB<'))->toBeFalse()
            ->and(preg_match('/\b(manager_shell|manager_features|ui)\.[a-z_]+\.[a-z_]+/', strip_tags($kurdish)))->toBe(0);
    });
});

it('answers a refused plan feature on a web page with a 403 offer, not a 500', function (): void {
    $center = $this->registerCenter('Nav Locked Page', 'owner@nav-locked.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    // A stand-in for any Action that refuses: what matters is the web mapping.
    Route::domain('{center}.'.app(PlatformHosts::class)->baseDomain())
        ->middleware(['web', 'tenant', 'locale', 'auth:web'])
        ->get('/manager/__shell-locked-probe', static fn () => throw new EntitlementRequired('finance'));

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);

        $this->get("http://{$slug}.localhost:8000/manager/__shell-locked-probe?locale=en")
            ->assertForbidden()
            ->assertSee('Available from Business')
            ->assertSee('class="feature-lock"', false)
            ->assertSee('aria-label="Center navigation"', false);

        // JSON keeps its envelope.
        $this->getJson("http://{$slug}.localhost:8000/manager/__shell-locked-probe")
            ->assertStatus(403)
            ->assertJsonPath('error.details.entitlement', 'finance');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
