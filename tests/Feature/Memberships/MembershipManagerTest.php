<?php

declare(strict_types=1);

use App\Livewire\Center\Benefits\MembershipMembers;
use App\Livewire\Center\MembershipPlans;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager membership plans page
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§10, 12, 22, 27. Edit a plan from
| its own figures, archive and put it back on sale, see who holds one — and
| the downgrade rule: readable with history, the upgrade page without.
|
*/

it('edits a plan from its stored figures and changes only what is sold next', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $plan = $this->membershipPlan($seed, $owner, [
            ['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 1250, 'uses_per_term' => 4],
            ['service' => null, 'discount_type' => 'fixed', 'amount_minor' => 5000],
        ], 50000, 30);

        $page = Livewire::actingAs($owner)->test(MembershipPlans::class)
            ->call('edit', $plan->uuid)
            ->assertSet('editing', $plan->uuid)
            ->assertSet('name', ['en' => 'Gold'])
            ->assertSet('price', '50000')
            ->assertSet('durationDays', '30')
            ->assertSet('benefits', [
                ['service' => $seed['service']->uuid, 'type' => 'percent', 'value' => '12.5', 'uses' => '4'],
                ['service' => '', 'type' => 'fixed', 'value' => '5000', 'uses' => ''],
            ])
            ->set('price', '60000')
            ->set('sortOrder', '2')
            ->call('save')
            ->assertSet('error', '')
            ->assertSet('showForm', false);

        $plan->refresh();

        expect($plan->price_minor)->toBe(60000)
            ->and($plan->sort_order)->toBe(2)
            ->and(MembershipPlan::query()->count())->toBe(1)
            ->and($plan->benefits()->count())->toBe(2);

        $page->assertSee('60,000');
    });
});

it('archives a plan, lists it under archived, and puts it back on sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $plan = $this->membershipPlan($seed, $owner);

        Livewire::actingAs($owner)->test(MembershipPlans::class)
            ->call('archive', $plan->uuid)
            ->assertSet('error', '')
            ->assertViewHas('archivedCount', 1)
            ->call('setView', 'archived')
            ->assertSee('Gold')
            ->call('restore', $plan->uuid)
            ->assertSet('error', '')
            ->assertViewHas('archivedCount', 0);

        expect($plan->refresh()->archived_at)->toBeNull();
    });
});

it('counts and lists the customers who hold a membership in force', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Member Mona', '0750 321 0000');
        $plan = $this->membershipPlan($seed, $owner);

        $invoice = $this->membershipInvoice($seed['branch'], $owner, $customer, $plan);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 50000);

        expect(CustomerMembership::query()->count())->toBe(1)
            ->and(app(MembershipsQuery::class)->liveCountsByPlan($owner))->toBe([$plan->uuid => 1]);

        Livewire::actingAs($owner)->test(MembershipPlans::class)
            ->assertSee('1 active member');

        Livewire::actingAs($owner)->test(MembershipMembers::class)
            ->assertSee('Member Mona')
            ->assertSee('Gold');
    });
});

it('keeps plans readable after a downgrade, and locks the page when there is nothing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();
        $this->revokeEntitlement('memberships');

        Livewire::actingAs($owner)->test(MembershipPlans::class)->assertViewIs('livewire.center.feature-locked');

        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $plan = $this->membershipPlan($seed, $owner);
        $this->revokeEntitlement('memberships');

        Livewire::actingAs($owner)->test(MembershipPlans::class)
            ->assertViewIs('livewire.center.membership-plans')
            ->assertSee('Gold')
            ->assertViewHas('canManage', false)
            ->call('archive', $plan->uuid)
            ->assertNotSet('error', '');

        expect($plan->refresh()->archived_at)->toBeNull();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
