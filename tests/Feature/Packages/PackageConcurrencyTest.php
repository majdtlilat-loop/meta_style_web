<?php

declare(strict_types=1);

use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Application\ActivatePackages;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\PackageTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Two desks, one package
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§24–25.
|
| The customer's package row is the lock every use takes after the sale's. A
| second desk using the last sessions waits, then decides on what is committed.
| Activation runs under the sale lock and one sale line can become one package
| — the database refuses a second.
|
*/

it('makes a second use of the last sessions wait for the first, then refuses it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer);
        $item = $package->items()->sole();

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);

        [$otherDesk, $release] = secondTenantConnection('tenant_package_desk');

        try {
            // Desk A is mid-redemption: it holds the customer's package row.
            $otherDesk->beginTransaction();
            $otherDesk->table('customer_packages')->where('id', $package->getKey())->lockForUpdate()->get();

            $waited = waitsForTenantLock(fn () => app(ApplyPackage::class)->apply($sale, $owner, $line, $package->uuid, 1, performed: true));

            expect($waited)->toBeTrue()
                ->and(PackageTransaction::query()->where('kind', PackageMovement::Redemption->value)->count())->toBe(0);

            // Desk A uses all five and commits.
            $otherDesk->table('package_transactions')->insert([
                'uuid' => (string) Str::uuid(),
                'customer_package_id' => $package->getKey(),
                'customer_package_item_id' => $item->getKey(),
                'kind' => 'redemption',
                'quantity' => 5,
                'source_type' => 'benefit',
                'source_uuid' => (string) Str::uuid(),
                'occurred_at' => now()->utc(),
                'created_at' => now()->utc(),
            ]);
            $otherDesk->commit();
        } finally {
            $release();
        }

        expect(fn () => app(ApplyPackage::class)->apply($sale->fresh() ?? $sale, $owner, $line, $package->uuid, 1, performed: true))
            ->toThrow(PackagesFailed::class, 'Only 0 sessions of it are left.');
    });
});

it('activates under the sale lock, and one sale line can never become two packages', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer);

        [$otherDesk, $release] = secondTenantConnection('tenant_activation_worker');

        try {
            // Another worker is activating the same sale: it holds the sale row.
            $otherDesk->beginTransaction();
            $otherDesk->table('sales')->where('id', $package->sale_id)->lockForUpdate()->get();

            expect(waitsForTenantLock(fn () => app(ActivatePackages::class)->syncSale($package->sale_id)))->toBeTrue();
        } finally {
            $release();
        }

        // Already active: running it again writes nothing.
        expect(app(ActivatePackages::class)->syncSale($package->sale_id))->toBe(0)
            ->and(CustomerPackage::query()->count())->toBe(1);

        // And the database refuses a second package for the same sale line.
        expect(fn () => CustomerPackage::query()->insert([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $package->customer_id,
            'package_definition_id' => $package->package_definition_id,
            'branch_id' => $package->branch_id,
            'name' => json_encode(['en' => 'Copy']),
            'price_minor' => 0,
            'currency' => 'IQD',
            'validity_days' => 1,
            'sale_id' => $package->sale_id,
            'sale_item_id' => $package->sale_item_id,
            'activated_at' => now()->utc(),
            'expires_at' => now()->utc()->addDay(),
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
