<?php

declare(strict_types=1);

use App\Livewire\Center\CustomerBenefitsPanel;
use App\Livewire\Center\PackageDefinitions;
use App\Livewire\Center\TillBenefits;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Package surfaces
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§13–16, 27.
|
| A package is defined on its own page, sold through the till like any line,
| and used on a performed service line — over the API or the till panel —
| never by booking. Cancelling needs a reason and moves no money.
|
*/

it('defines packages, reads a customer\'s, covers and uncovers a line, and cancels over the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    [$seed, $customer] = $this->asCenter($center['tenant'], function (): array {
        $this->grantPackages();

        return [$this->seedBookableCenter(), $this->seedCustomer()];
    });

    $definition = $this->withHeaders($headers)->postJson('/api/v1/tenant/package-definitions', [
        'name' => ['en' => 'Five cuts'],
        'price_minor' => 80000,
        'validity_days' => 90,
        'items' => [['service' => $seed['service']->uuid, 'quantity' => 5]],
    ])->assertStatus(201)->assertJsonPath('data.definition.items.0.quantity', 5)->json('data.definition.uuid');

    $this->withHeaders($headers)->putJson("/api/v1/tenant/package-definitions/{$definition}", [
        'name' => ['en' => 'Five cuts'],
        'price_minor' => 75000,
        'validity_days' => 120,
        'items' => [['service' => $seed['service']->uuid, 'quantity' => 5]],
    ])->assertStatus(200)->assertJsonPath('data.definition.validity_days', 120);

    $this->withHeaders($headers)->getJson('/api/v1/tenant/package-definitions')->assertStatus(200)->assertJsonCount(1, 'data.definitions');

    [$packageUuid, $draftUuid, $lineUuid] = $this->asCenter($center['tenant'], function () use ($seed, $customer): array {
        $owner = $this->ownerWithCatalogAccess();
        $package = $this->paidPackage($seed, $owner, $customer);
        [$draft, $line] = $this->serviceDraft($seed, $owner, $customer, 2);

        return [$package->uuid, $draft->uuid, $line];
    });

    $this->withHeaders($headers)->getJson("/api/v1/tenant/customers/{$customer->uuid}/packages")
        ->assertStatus(200)->assertJsonPath('data.packages.0.uuid', $packageUuid)->assertJsonPath('data.packages.0.items.0.left', 5);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/sales/{$draftUuid}/items/{$lineUuid}/package", ['package' => $packageUuid, 'sessions' => 3, 'performed' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'PACKAGES.POLICY_VIOLATION');

    $this->withHeaders($headers)->postJson("/api/v1/tenant/sales/{$draftUuid}/items/{$lineUuid}/package", ['package' => $packageUuid, 'sessions' => 2, 'performed' => true])
        ->assertStatus(201)->assertJsonPath('data.sale.grand_total.amount', 0);

    $this->withHeaders($headers)->deleteJson("/api/v1/tenant/sales/{$draftUuid}/items/{$lineUuid}/package")
        ->assertStatus(200)->assertJsonPath('data.sale.grand_total.amount', 40000);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/customer-packages/{$packageUuid}/cancel", ['reason' => 'x'])
        ->assertStatus(422);

    $this->withHeaders($headers)->postJson("/api/v1/tenant/customer-packages/{$packageUuid}/cancel", ['reason' => 'Customer relocated'])
        ->assertStatus(200)->assertJsonPath('data.package.state', 'cancelled')->assertJsonPath('data.package.items.0.left', 0);
});

it('drives the packages page, the till panel and the customer panel through the same Actions', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->actingAs($owner, 'web');

        Livewire::test(PackageDefinitions::class)
            ->set('name', ['en' => 'Three blow-dries'])
            ->set('price', '45000')
            ->set('validityDays', '60')
            ->set('items', [['service' => $seed['service']->uuid, 'quantity' => '3']])
            ->call('save')
            ->assertSet('error', '')
            ->assertSee('Three blow-dries');

        expect(PackageDefinition::query()->sole()->price_minor)->toBe(45000);

        $package = $this->paidPackage($seed, $owner, $customer);
        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);

        Livewire::test(TillBenefits::class, ['sale' => $sale->uuid])
            ->set('coverWith', [$line => 'package:'.$package->uuid])
            ->set('performed', [$line => true])
            ->call('cover', $line)
            ->assertSet('error', '')
            ->assertSee('Withdraw')
            ->call('uncover', $line, 'package')
            ->assertSet('error', '');

        expect($sale->fresh()?->discount_total_minor)->toBe(0);

        Livewire::test(CustomerBenefitsPanel::class, ['customer' => $customer->uuid])
            ->assertSee('5 of 5 left')
            ->set('cancelling', 'package:'.$package->uuid)
            ->set('cancelReason', 'Refunded at the desk')
            ->call('cancel')
            ->assertSet('error', '');

        expect(CustomerPackage::query()->whereKey($package->getKey())->value('cancel_reason'))->toBe('Refunded at the desk');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
