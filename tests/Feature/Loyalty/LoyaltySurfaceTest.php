<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\CustomerBenefitsPanel;
use App\Livewire\Center\Loyalty;
use App\Livewire\Center\TillBenefits;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Loyalty surfaces
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§19, 27.
|
| The API and the pages call the same Actions. These drive both ends and assert
| outcomes: rules and tiers over HTTP, a customer's points, a hand adjustment,
| points redeemed on a draft — and a customer reading their own points through
| an allow-list, with nothing internal in it.
|
*/

it('configures the rules, manages tiers, adjusts and redeems over the API — with the codes a client branches on', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    [$customer, $sale] = $this->asCenter($center['tenant'], function (): array {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        [$sale] = $this->serviceDraft($seed, $owner, $customer);

        return [$customer, $sale];
    });

    $this->withHeaders($headers)->putJson('/api/v1/tenant/loyalty/program', [
        'spend_points' => 1, 'spend_unit_minor' => 1000, 'min_spend_minor' => 0, 'visit_points' => 0,
        'point_value_minor' => 100, 'min_redeem_points' => 5, 'expiry_days' => 365,
    ])->assertStatus(200)->assertJsonPath('data.program.expiry_days', 365);

    $tier = $this->withHeaders($headers)->postJson('/api/v1/tenant/loyalty/tiers', ['name' => ['en' => 'Silver'], 'threshold_points' => 100])
        ->assertStatus(201)->json('data.tier.uuid');

    $this->withHeaders($headers)->putJson("/api/v1/tenant/loyalty/tiers/{$tier}", ['name' => ['en' => 'Silver+'], 'threshold_points' => 150])
        ->assertStatus(200)->assertJsonPath('data.tier.threshold_points', 150);

    $this->withHeaders($headers)->getJson('/api/v1/tenant/loyalty/program')
        ->assertStatus(200)->assertJsonCount(1, 'data.tiers')->assertJsonPath('data.program.min_redeem_points', 5);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/customers/{$customer->uuid}/loyalty/adjustments", ['direction' => 'in', 'points' => 30, 'reason' => 'Opening balance'])
        ->assertStatus(201)->assertJsonPath('data.loyalty.available_points', 30);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/customers/{$customer->uuid}/loyalty/adjustments", ['direction' => 'out', 'points' => 31, 'reason' => 'Too many'])
        ->assertStatus(422)->assertJsonPath('error.code', 'LOYALTY.POLICY_VIOLATION');

    $this->withHeaders($headers)->postJson("/api/v1/tenant/sales/{$sale->uuid}/loyalty-redemption", ['points' => 3])
        ->assertStatus(422)->assertJsonPath('error.code', 'LOYALTY.POLICY_VIOLATION');

    $this->withHeaders($headers)->postJson("/api/v1/tenant/sales/{$sale->uuid}/loyalty-redemption", ['points' => 10])
        ->assertStatus(201)->assertJsonPath('data.sale.discount_total.amount', 1000);

    $this->withHeaders($headers)->getJson("/api/v1/tenant/customers/{$customer->uuid}/loyalty")
        ->assertStatus(200)->assertJsonPath('data.loyalty.available_points', 20)->assertJsonCount(2, 'data.loyalty.recent');

    $this->withHeaders($headers)->deleteJson("/api/v1/tenant/sales/{$sale->uuid}/loyalty-redemption")
        ->assertStatus(200)->assertJsonPath('data.sale.discount_total.amount', 0);

    $this->withHeaders($headers)->deleteJson("/api/v1/tenant/loyalty/tiers/{$tier}")
        ->assertStatus(200)->assertJsonPath('data.tier.archived', true);
});

it('refuses staff without the loyalty permissions', function (): void {
    $center = $this->registerCenter();

    $customer = $this->asCenter($center['tenant'], function () {
        $this->grantLoyalty();
        $this->seedBookableCenter();

        return $this->seedCustomer();
    });

    $viewer = $this->asCenter($center['tenant'], fn () => $this->staffWith([Permission::LoyaltyView], 'viewer@alpha.test'));
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant'], $viewer));

    $this->withHeaders($headers)->getJson("/api/v1/tenant/customers/{$customer->uuid}/loyalty")->assertStatus(200);

    $this->withHeaders($headers)->putJson('/api/v1/tenant/loyalty/program', [
        'spend_points' => 1, 'spend_unit_minor' => 1000, 'min_spend_minor' => 0, 'visit_points' => 0,
        'point_value_minor' => 100, 'min_redeem_points' => 0, 'expiry_days' => null,
    ])->assertStatus(403);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/customers/{$customer->uuid}/loyalty/adjustments", ['direction' => 'in', 'points' => 5, 'reason' => 'Goodwill'])
        ->assertStatus(403);
});

it('shows a signed-in customer their own points through an allow-list', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->grantCustomerAccounts();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $sara), $owner, PaymentMethod::Cash, 20000);
    });

    // A staff token is not a customer — asked first, before any customer has
    // been authenticated in this application instance.
    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($center['tenant'])))
        ->getJson('/api/v1/customer/benefits')
        ->assertStatus(401);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    $response = $this->withHeaders($this->tokenHeaders((string) $token))
        ->getJson('/api/v1/customer/benefits')
        ->assertStatus(200)
        ->assertJsonPath('data.benefits.loyalty.available_points', 20)
        ->assertJsonPath('data.benefits.loyalty.recent.0.kind', 'earn');

    $recent = (array) $response->json('data.benefits.loyalty.recent.0');

    // Nothing internal: no uuid, no reason, no staff name, no source.
    expect(array_keys($recent))->toBe(['kind', 'direction', 'points', 'date'])
        ->and(array_key_exists('unrecovered_points', (array) $response->json('data.benefits.loyalty')))->toBeFalse()
        ->and(array_key_exists('lifetime_points', (array) $response->json('data.benefits.loyalty')))->toBeFalse();

});

it('drives the loyalty page, the customer panel and the till panel through the same Actions', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->actingAs($owner, 'web');

        Livewire::test(Loyalty::class)
            ->set('spendPoints', '1')
            ->set('spendUnit', '1000')
            ->set('pointValue', '100')
            ->set('minRedeem', '1')
            ->call('save')
            ->assertSet('error', '')
            ->set('tierName', ['en' => 'Gold'])
            ->set('tierThreshold', '500')
            ->call('addTier')
            ->assertSet('error', '')
            ->assertSee('Gold');

        expect(LoyaltyProgram::current()?->spend_unit_minor)->toBe(1000);

        Livewire::test(CustomerBenefitsPanel::class, ['customer' => $customer->uuid])
            ->set('direction', 'in')
            ->set('points', '40')
            ->set('reason', 'Welcome gift')
            ->call('adjust')
            ->assertSet('error', '')
            ->assertSee('Welcome gift')
            // No reason, no adjustment.
            ->set('points', '5')
            ->set('reason', 'no')
            ->call('adjust')
            ->assertSet('error', 'A points adjustment needs a reason.');

        [$sale] = $this->serviceDraft($seed, $owner, $customer);

        Livewire::test(TillBenefits::class, ['sale' => $sale->uuid])
            ->set('points', '15')
            ->call('redeem')
            ->assertSet('error', '')
            ->assertDispatched('sale-changed');

        expect($sale->fresh()?->discount_total_minor)->toBe(1500)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(25);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
