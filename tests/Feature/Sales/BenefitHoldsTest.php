<?php

declare(strict_types=1);

use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\MembershipBenefitUsage;
use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Application\PackageLedger;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Models\PackageTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\ReleaseStaleBenefits;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| A benefit on a draft is a hold, and a hold expires
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7.
|
| Withdrawing a benefit, discarding the draft or voiding the sale all give it
| back. A draft nobody ever finishes is none of those — so an untouched draft
| holding points, sessions or a membership use gives them back after
| `HOLD_HOURS`, and the draft is re-priced to what it costs without them.
|
*/

it('gives back points, sessions and membership uses held on a draft nobody finished', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->grantPackages();
        $this->grantMemberships();
        $this->outlastTrial();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000]);
        $customer = $this->seedCustomer();

        // Points to spend, a package to draw on, a membership to use.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $package = $this->paidPackage($seed, $owner, $customer, priceMinor: 0);
        $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, priceMinor: 0));
        $benefit = CustomerMembership::query()->sole()->benefits()->sole();

        // Three drafts, each holding one kind of benefit, then abandoned.
        [$points] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($points, $owner, 5);

        [$sessions, $sessionLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyPackage::class)->apply($sessions, $owner, $sessionLine, $package->uuid, 1, performed: true);

        [$member, $memberLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyMembershipBenefit::class)->apply($member, $owner, $memberLine, $benefit->uuid);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(15)
            ->and(array_sum(app(PackageLedger::class)->left($package)))->toBe(4)
            ->and(MembershipBenefitUsage::query()->where('kind', UsageKind::Use->value)->count())->toBe(1)
            ->and($points->fresh()?->grand_total_minor)->toBe(15000);

        // Nothing is stale yet.
        expect(app(ReleaseStaleBenefits::class)->release())->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(15);

        // A day later, with nobody having touched them.
        $this->travel(ReleaseStaleBenefits::HOLD_HOURS + 1)->hours();

        expect(app(ReleaseStaleBenefits::class)->release())->toBe(3);

        // Everything is the customer's again, and each draft costs full price.
        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            ->and(array_sum(app(PackageLedger::class)->left($package)))->toBe(5)
            ->and(MembershipBenefitUsage::query()->where('kind', UsageKind::Reversal->value)->count())->toBe(1)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(1)
            ->and(PackageTransaction::query()->where('kind', PackageMovement::Reversal->value)->count())->toBe(1)
            ->and($points->fresh()?->grand_total_minor)->toBe(20000)
            ->and($sessions->fresh()?->grand_total_minor)->toBe(20000)
            ->and($member->fresh()?->grand_total_minor)->toBe(20000)
            // The drafts are still drafts: the cashier can still finish them.
            ->and($points->fresh()?->status)->toBe(SaleStatus::Draft)
            ->and(SaleAdjustment::query()->where('type', AdjustmentType::BenefitDiscount->value)->count())->toBe(0)
            ->and(DB::connection('tenant')->table('audit_logs')->where('action', 'sale.benefit_released')->count())->toBe(3);

        // Running it again releases nothing and gives nothing back twice.
        expect(app(ReleaseStaleBenefits::class)->release())->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(1);
    });
});

it('leaves a hold alone while the draft is still being worked on, and never touches a published sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000]);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        // One sale that was finished and published, one draft still in hand.
        [$finalized] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($finalized, $owner, 10);
        app(FinalizeSale::class)($finalized->fresh() ?? $finalized, $owner);

        [$open] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($open, $owner, 5);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(5);

        $this->travel(ReleaseStaleBenefits::HOLD_HOURS + 1)->hours();

        // The cashier comes back to the open draft and adds a line to it.
        app(AddSaleLine::class)($open->fresh() ?? $open, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        expect(app(ReleaseStaleBenefits::class)->release())->toBe(0)
            // The published sale keeps its redemption: it is not a hold.
            ->and(Sale::query()->whereKey($finalized->getKey())->value('discount_total_minor'))->toBe(10000)
            // And the draft being worked on keeps its hold.
            ->and($open->fresh()?->discount_total_minor)->toBe(5000)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(5);
    });
});

/*
|--------------------------------------------------------------------------
| A hold is released under the sale lock, or not at all
|--------------------------------------------------------------------------
|
| The query that finds expired holds runs unlocked, so everything it decided
| must be decided AGAIN from the locked rows. A till that came back to the
| draft, or finished it, in between keeps the benefit — a release and a
| finalization can never both have it.
|
*/

it('waits for the sale lock rather than releasing a hold a till is working under', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000]);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        [$sale] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($sale, $owner, 5);

        $this->travel(ReleaseStaleBenefits::HOLD_HOURS + 1)->hours();

        [$till, $release] = secondTenantConnection('tenant_hold_till');

        try {
            // A till is inside its own transaction on this very sale.
            $till->beginTransaction();
            $till->table('sales')->where('id', $sale->getKey())->lockForUpdate()->get();

            expect(waitsForTenantLock(fn () => app(ReleaseStaleBenefits::class)->release()))->toBeTrue()
                // Nothing was given back behind the till's back.
                ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(0)
                ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(15)
                ->and(SaleAdjustment::query()->where('type', AdjustmentType::BenefitDiscount->value)->count())->toBe(1);
        } finally {
            $release();
        }
    });
});

it('re-checks each hold under the lock, and leaves the ones whose draft was touched or finished in between', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000]);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        [$touched] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($touched, $owner, 5);

        [$finished] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($finished, $owner, 5);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(10);

        $this->travel(ReleaseStaleBenefits::HOLD_HOURS + 1)->hours();

        [$till, $release] = secondTenantConnection('tenant_hold_race');

        try {
            $raced = false;

            // Both holds are picked up by one unlocked query. The till commits
            // its own work in the window between that query and the lock: the
            // exact interleaving the re-check under the lock exists for.
            DB::connection('tenant')->listen(function (QueryExecuted $query) use (&$raced, $till, $touched, $finished): void {
                if ($raced || ! str_contains($query->sql, 'sale_adjustments')) {
                    return;
                }

                $raced = true;

                $till->table('sales')->where('id', $touched->getKey())->update(['updated_at' => now()->utc()]);
                $till->table('sales')->where('id', $finished->getKey())->update(['status' => SaleStatus::Finalized->value]);
            });

            expect(app(ReleaseStaleBenefits::class)->release())->toBe(0)
                ->and($raced)->toBeTrue()
                // Neither customer's points came back, and neither hold moved.
                ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(0)
                ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(10)
                ->and(SaleAdjustment::query()->where('type', AdjustmentType::BenefitDiscount->value)->count())->toBe(2)
                // The finished sale kept the redemption it consumed.
                ->and(Sale::query()->whereKey($finished->getKey())->value('discount_total_minor'))->toBe(5000)
                ->and($touched->fresh()?->discount_total_minor)->toBe(5000)
                ->and(DB::connection('tenant')->table('audit_logs')->where('action', 'sale.benefit_released')->count())->toBe(0);
        } finally {
            $release();
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
