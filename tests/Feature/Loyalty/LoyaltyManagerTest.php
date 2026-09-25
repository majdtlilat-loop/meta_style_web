<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\Benefits\LoyaltyMembers;
use App\Livewire\Center\CustomerBenefitsPanel;
use App\Livewire\Center\Loyalty;
use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\Actions\ManageLoyaltyTier;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use App\Modules\Loyalty\Domain\Models\LoyaltyTier;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager loyalty page
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§6, 9, 21, 22, 27. Status, rules and
| their versions, tiers (add, edit, archive, restore), members and totals —
| and the downgrade rule: the upgrade page with nothing to read, the read-only
| page with history.
|
*/

it('refuses the page without loyalty.view', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $this->grantLoyalty();
        $nobody = $this->staffWith([Permission::CustomerView], 'noloyalty@alpha.test');

        $this->actingAs($nobody);
        $this->get("http://{$slug}.localhost:8000/manager/loyalty")->assertForbidden();
    });
});

it('shows the upgrade page when loyalty is not owned and there is nothing to read', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->revokeEntitlement('loyalty');

        Livewire::actingAs($owner)->test(Loyalty::class)
            ->assertViewIs('livewire.center.feature-locked')
            ->assertDontSee('spend-points');
    });
});

it('shows the plan notice instead of a customer\'s benefits the center never had, and reads nothing', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->revokeEntitlement('loyalty');
        $this->revokeEntitlement('memberships');
        $this->revokeEntitlement('packages');
        $customer = $this->seedCustomer();

        Livewire::actingAs($owner)
            ->test(CustomerBenefitsPanel::class, ['customer' => $customer->uuid, 'section' => 'loyalty'])
            ->assertSee('feature-lock-notice', false)
            ->assertViewHas('loyalty', null)
            ->assertDontSee('adjust-points');

        Livewire::actingAs($owner)
            ->test(CustomerBenefitsPanel::class, ['customer' => $customer->uuid, 'section' => 'plans'])
            ->assertViewHas('memberships', null)
            ->assertViewHas('packages', null)
            ->assertViewHas('locks', fn (array $locks): bool => array_keys($locks) === ['memberships', 'packages']
                && $locks['memberships']['history'] === false
                && $locks['packages']['history'] === false);
    });
});

it('keeps the history readable after a downgrade, and refuses new changes', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['spend_points' => 2, 'spend_unit_minor' => 1000]);
        app(ManageLoyaltyTier::class)->save($owner, ['en' => 'Gold'], 500);

        $this->revokeEntitlement('loyalty');

        Livewire::actingAs($owner)->test(Loyalty::class)
            ->assertViewIs('livewire.center.loyalty')
            ->assertSee('Gold')
            ->assertSee('feature-lock-notice', false)
            ->assertViewHas('canManage', false)
            // The Action refuses on the server whatever the screen shows.
            ->set('spendPoints', '9')
            ->call('save')
            ->assertNotSet('error', '');

        expect(LoyaltyProgram::current()?->spend_points)->toBe(2);
    });
});

it('edits, archives and restores a tier, and records every rule version', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $owner = $this->ownerWithCatalogAccess();

        $page = Livewire::actingAs($owner)->test(Loyalty::class)
            ->set('spendPoints', '1')
            ->set('spendUnit', '1000')
            ->set('pointValue', '100')
            ->call('save')
            ->assertSet('error', '')
            ->set('visitPoints', '5')
            ->call('save')
            ->assertSet('error', '')
            ->set('tierName', ['en' => 'Silver'])
            ->set('tierNote', ['en' => '5% off'])
            ->set('tierThreshold', '200')
            ->call('addTier')
            ->assertSet('error', '');

        expect(LoyaltyRuleVersion::query()->count())->toBe(2)
            ->and($page->viewData('versions'))->toHaveCount(2)
            ->and($page->viewData('program')['earns_on_visits'])->toBeTrue();

        $tier = LoyaltyTier::query()->sole();

        $page->call('editTier', $tier->uuid)
            ->assertSet('tierName', ['en' => 'Silver'])
            ->assertSet('tierNote', ['en' => '5% off'])
            ->assertSet('tierThreshold', '200')
            ->set('tierName', ['en' => 'Silver Plus'])
            ->set('tierThreshold', '250')
            ->call('saveTier')
            ->assertSet('error', '')
            ->assertSee('Silver Plus');

        expect($tier->refresh()->threshold_points)->toBe(250)
            ->and(LoyaltyTier::query()->count())->toBe(1);

        $page->call('archiveTier', $tier->uuid)->assertSet('error', '')->assertDontSee('Silver Plus');
        $page->set('showArchivedTiers', true)->assertSee('Silver Plus')
            ->call('restoreTier', $tier->uuid)->assertSet('error', '');

        expect($tier->refresh()->archived_at)->toBeNull();
    });
});

it('lists who holds points with totals, and adjusts from the customer panel', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 100]);

        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');
        $ali = $this->seedCustomer('Ali Hassan', '0770 987 6543');

        app(AdjustPoints::class)($sara->uuid, $owner, PointsDirection::In, 300, 'Welcome gift');
        app(AdjustPoints::class)($ali->uuid, $owner, PointsDirection::In, 50, 'Apology');

        // A hand adjustment is not EARNED: it adds to the balance and never to
        // the qualifying lifetime points that tiers are measured on (ADR-062).
        expect(app(LoyaltyQuery::class)->totals($owner))->toBe([
            'members' => 2,
            'outstanding_points' => 350,
            'lifetime_points' => 0,
        ]);

        Livewire::actingAs($owner)->test(LoyaltyMembers::class)
            ->assertSeeInOrder(['Sara Ahmed', 'Ali Hassan'])
            ->set('search', 'Ali')
            ->assertSee('Ali Hassan')
            ->assertDontSee('Sara Ahmed');

        // The panel names each movement in words and shows what points are worth.
        Livewire::actingAs($owner)
            ->test(CustomerBenefitsPanel::class, ['customer' => $sara->uuid, 'section' => 'loyalty'])
            ->assertSee('Adjusted by staff')
            ->assertSee('Welcome gift')
            ->set('direction', 'out')
            ->set('points', '1000')
            ->set('reason', 'Correction')
            ->call('adjust')
            ->assertSet('error', 'Only 300 points are available to take away.');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
