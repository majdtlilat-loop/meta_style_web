<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Loyalty, memberships and packages — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §23.
|
| Benefits are the center's, about the center's own customers. The same phone
| number at two centers is two customers with two balances; a package or a
| membership bought at one is unknown at the other; and with no center bound,
| every benefit query fails rather than answering from somewhere.
|
*/

it('never shares a points balance between two centers, even for the same phone number', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $sara), $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($sara)?->balance)->toBe(20);
    });

    $this->asCenter($beta['tenant'], function (): void {
        $this->grantLoyalty();
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        // Same person, same phone — a different center, and nothing earned here.
        expect($this->loyaltyAccountOf($sara))->toBeNull()
            ->and(LoyaltyAccount::query()->count())->toBe(0)
            ->and(LoyaltyTransaction::query()->count())->toBe(0);
    });
});

it('never lets one center\'s package or membership be used, or seen, at another', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    [$packageUuid, $benefitUuid] = $this->asCenter($alpha['tenant'], function (): array {
        $this->grantPackages();
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $package = $this->paidPackage($seed, $owner, $sara);
        $this->membershipInvoice($seed['branch'], $owner, $sara, $this->membershipPlan($seed, $owner, priceMinor: 0));

        return [$package->uuid, CustomerMembershipBenefit::query()->sole()->uuid];
    });

    $this->asCenter($beta['tenant'], function () use ($packageUuid, $benefitUuid): void {
        $this->grantPackages();
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        [$sale, $line] = $this->serviceDraft($seed, $owner, $sara);

        expect(fn () => app(ApplyPackage::class)->apply($sale, $owner, $line, $packageUuid, 1, performed: true))
            ->toThrow(PackagesFailed::class, 'That package is not this customer\'s.')
            ->and(fn () => app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, $benefitUuid))
            ->toThrow(MembershipsFailed::class, 'That membership is not this customer\'s.')
            ->and(app(MembershipsQuery::class)->forCustomer($sara->uuid, $owner)['memberships'])->toBe([])
            ->and(CustomerPackage::query()->count())->toBe(0)
            ->and(CustomerMembership::query()->count())->toBe(0)
            ->and($sale->fresh()?->discount_total_minor)->toBe(0);
    });
});

it('fails closed when no center is bound, and leaves none bound after benefit work', function (): void {
    $center = $this->registerCenter();

    expect(fn (): int => LoyaltyAccount::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => LoyaltyTransaction::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => CustomerPackage::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => CustomerMembership::query()->count())->toThrow(TenantConnectionNotInitialized::class);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $this->paidPackage($seed, $this->ownerWithCatalogAccess(), $this->seedCustomer());
    });

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('puts every benefit table in the center database, with no tenant column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $tables = [
            'loyalty_programs', 'loyalty_tiers', 'loyalty_accounts', 'loyalty_transactions', 'loyalty_invoice_rules',
            'loyalty_rule_versions', 'loyalty_earning_observations',
            'membership_plans', 'membership_plan_benefits', 'customer_memberships', 'customer_membership_benefits', 'membership_benefit_usages',
            'package_definitions', 'package_definition_items', 'customer_packages', 'customer_package_items', 'package_transactions',
        ];

        foreach ($tables as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue("{$table} should exist")
                ->and(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))->toBeFalse("{$table} must not carry tenant_id");
        }

        // No benefit state on the operational or money tables.
        foreach (['appointments', 'journey_stages', 'invoices', 'payments'] as $table) {
            foreach (['points', 'loyalty_points', 'package_id', 'membership_id', 'sessions_left'] as $column) {
                expect(Schema::connection('tenant')->hasColumn($table, $column))->toBeFalse("{$table}.{$column}");
            }
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
