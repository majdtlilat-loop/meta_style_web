<?php

declare(strict_types=1);

use App\Livewire\Center\CustomerBenefitsPanel;
use App\Livewire\Center\MembershipPlans;
use App\Livewire\Center\TillBenefits;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Membership surfaces
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§11–12, 27.
|
| A plan is defined on its own page and SOLD through the till like any line —
| the offerings list, then `kind=offering` — never through a checkout of its
| own. Using it is a change to a draft line, over the API or the till panel.
|
*/

it('defines a plan, sells it through the till, and applies and withdraws a member\'s price over the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    [$seed, $customer] = $this->asCenter($center['tenant'], function (): array {
        $this->grantMemberships();

        return [$this->seedBookableCenter(), $this->seedCustomer()];
    });

    $plan = $this->withHeaders($headers)->postJson('/api/v1/tenant/membership-plans', [
        'name' => ['en' => 'Gold'],
        'price_minor' => 50000,
        'duration_days' => 30,
        'benefits' => [['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 2500, 'uses_per_term' => 4]],
    ])->assertStatus(201)->assertJsonPath('data.plan.benefits.0.percent', '25')->json('data.plan.uuid');

    $this->withHeaders($headers)->postJson('/api/v1/tenant/membership-plans', [
        'name' => ['en' => 'Broken'], 'price_minor' => 1, 'duration_days' => 30,
        'benefits' => [['discount_type' => 'fixed']],
    ])->assertStatus(422)->assertJsonPath('error.code', 'MEMBERSHIPS.POLICY_VIOLATION');

    // Sold at the till: the offering is listed, then added as a line.
    [$saleUuid, $invoice] = $this->asCenter($center['tenant'], function () use ($seed, $customer): array {
        $sale = $this->customerSale($seed['branch'], $this->ownerWithCatalogAccess(), $customer);

        return [$sale->uuid, null];
    });

    $this->withHeaders($headers)->getJson('/api/v1/tenant/sales/offerings')
        ->assertStatus(200)->assertJsonPath('data.offerings.0.type', 'membership')->assertJsonPath('data.offerings.0.reference', $plan);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/sales/{$saleUuid}/items", ['kind' => 'offering', 'offering_type' => 'membership', 'offering' => $plan])
        ->assertStatus(201)->assertJsonPath('data.sale.grand_total.amount', 50000);

    $this->asCenter($center['tenant'], function () use ($saleUuid): void {
        $owner = $this->ownerWithCatalogAccess();
        $invoice = app(FinalizeSale::class)(Sale::query()->where('uuid', $saleUuid)->firstOrFail(), $owner)->invoice;
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 50000);
    });

    $benefit = $this->withHeaders($headers)->getJson("/api/v1/tenant/customers/{$customer->uuid}/memberships")
        ->assertStatus(200)->assertJsonPath('data.memberships.0.state', 'active')
        ->assertJsonPath('data.memberships.0.benefits.0.uses_left', 4)
        ->json('data.memberships.0.benefits.0.uuid');

    [$draftUuid, $lineUuid] = $this->asCenter($center['tenant'], function () use ($seed, $customer): array {
        [$draft, $line] = $this->serviceDraft($seed, $this->ownerWithCatalogAccess(), $customer);

        return [$draft->uuid, $line];
    });

    $this->withHeaders($headers)->postJson("/api/v1/tenant/sales/{$draftUuid}/items/{$lineUuid}/membership-benefit", ['benefit' => $benefit])
        ->assertStatus(201)->assertJsonPath('data.sale.discount_total.amount', 5000);

    $this->withHeaders($headers)->deleteJson("/api/v1/tenant/sales/{$draftUuid}/items/{$lineUuid}/membership-benefit")
        ->assertStatus(200)->assertJsonPath('data.sale.discount_total.amount', 0);

    $membership = $this->asCenter($center['tenant'], fn (): string => CustomerMembership::query()->sole()->uuid);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/customer-memberships/{$membership}/cancel", ['reason' => 'Moved abroad'])
        ->assertStatus(200)->assertJsonPath('data.membership.state', 'cancelled')->assertJsonPath('data.membership.cancel_reason', 'Moved abroad');

    $this->withHeaders($headers)->deleteJson("/api/v1/tenant/membership-plans/{$plan}")
        ->assertStatus(200)->assertJsonPath('data.plan.archived', true);
});

it('drives the plans page, the till panel and the customer panel through the same Actions', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->actingAs($owner, 'web');

        Livewire::test(MembershipPlans::class)
            ->set('name', ['en' => 'Silver'])
            ->set('price', '0')
            ->set('durationDays', '30')
            ->set('benefits', [['service' => $seed['service']->uuid, 'type' => 'percent', 'value' => '12.5', 'uses' => '']])
            ->call('save')
            ->assertSet('error', '')
            ->assertSee('Silver');

        /** @var MembershipPlan $plan */
        $plan = MembershipPlan::query()->sole();

        expect($plan->benefits()->sole()->basis_points)->toBe(1250);

        // Sold (free) at the till panel, then used on another sale's line.
        $sale = $this->customerSale($seed['branch'], $owner, $customer);

        Livewire::test(TillBenefits::class, ['sale' => $sale->uuid])
            ->set('offering', 'membership:'.$plan->uuid)
            ->call('addOffering')
            ->assertSet('error', '')
            ->assertDispatched('sale-changed');

        app(FinalizeSale::class)($sale->fresh() ?? $sale, $owner);

        [$next, $line] = $this->serviceDraft($seed, $owner, $customer);
        $benefit = CustomerMembership::query()->sole()->benefits()->sole();

        Livewire::test(TillBenefits::class, ['sale' => $next->uuid])
            ->assertSee('Silver')
            ->set('coverWith', [$line => 'membership:'.$benefit->uuid])
            ->call('cover', $line)
            ->assertSet('error', '');

        expect($next->fresh()?->discount_total_minor)->toBe(2500)
            ->and(SaleItem::query()->where('uuid', $line)->exists())->toBeTrue();

        Livewire::test(CustomerBenefitsPanel::class, ['customer' => $customer->uuid])
            ->assertSee('Silver')
            ->set('cancelling', 'membership:'.CustomerMembership::query()->sole()->uuid)
            ->set('cancelReason', 'Asked to stop')
            ->call('cancel')
            ->assertSet('error', '');

        expect(CustomerMembership::query()->sole()->cancel_reason)->toBe('Asked to stop');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
