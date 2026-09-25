<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Application\Actions\CancelCustomerPackage;
use App\Modules\Packages\Application\PackageLedger;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Exceptions\PackagesFailed;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Packages\Domain\Models\PackageTransaction;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CheckoutJourney;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Using a package at checkout
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§14, 16–18, 22.
|
| A package covers a service that was PERFORMED — a stage of the visit, or a
| service charged at the till — one unit per session, on that line only. Never
| because something was booked. Every redemption names its sale and, for a
| visit, the stage it covered; every return is a reversal of exactly it.
|
*/

function sessionsLeft(CustomerPackage $package): int
{
    return array_sum(app(PackageLedger::class)->left($package));
}

it('covers the performed stage of a visit, recording the stage and the sale it covered', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer);

        $journey = app(CreateWalkInVisit::class)(new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            customerUuid: $customer->uuid,
            idempotencyToken: (string) Str::uuid(),
        ), $owner);

        $stage = $journey->stages()->sole();
        app(TransitionStage::class)($stage, StageStatus::InService, $owner);
        app(TransitionStage::class)($stage->fresh() ?? $stage, StageStatus::Completed, $owner);
        app(CompleteJourney::class)($journey->fresh() ?? $journey, $owner);

        // Completing the visit consumed nothing: consumption is the checkout's.
        expect(sessionsLeft($package))->toBe(5);

        $sale = app(CheckoutJourney::class)($journey->fresh() ?? $journey, $owner);
        /** @var SaleItem $line */
        $line = SaleItem::query()->where('sale_id', $sale->getKey())->where('journey_stage_id', $stage->getKey())->sole();

        app(ApplyPackage::class)->apply($sale, $owner, $line->uuid, $package->uuid);

        /** @var PackageTransaction $redemption */
        $redemption = PackageTransaction::query()->where('kind', PackageMovement::Redemption->value)->sole();

        expect($sale->fresh()?->grand_total_minor)->toBe(0)
            ->and(sessionsLeft($package))->toBe(4)
            ->and($redemption->journey_stage_id)->toBe((int) $stage->getKey())
            ->and($redemption->sale_uuid)->toBe($sale->uuid);
    });
});

it('refuses a package on a line typed at the till until somebody confirms the service was performed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer);

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);

        // Adding a service to a cart is not performing it.
        expect(fn () => app(ApplyPackage::class)->apply($sale, $owner, $line, $package->uuid))
            ->toThrow(PackagesFailed::class, 'Confirm that the service was performed before using a package session.');

        expect(sessionsLeft($package))->toBe(5)
            ->and($sale->fresh()?->discount_total_minor)->toBe(0);

        // Confirmed by the person at the till: allowed, and the audit says so.
        app(ApplyPackage::class)->apply($sale->fresh() ?? $sale, $owner, $line, $package->uuid, 1, performed: true);

        expect(sessionsLeft($package))->toBe(4)
            ->and(DB::connection('tenant')->table('audit_logs')
                ->where('action', 'package.redeemed')
                ->value('after'))->toContain('staff_confirmed');
    });
});

it('covers one unit of a two-unit line and leaves the other charged', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer);

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer, 2, [$seed['addon']->uuid]);

        app(ApplyPackage::class)->apply($sale, $owner, $line, $package->uuid, 1, performed: true);

        // Two haircuts with add-ons are 50,000; one session covers 20,000 of it.
        expect($sale->fresh()?->discount_total_minor)->toBe(20000)
            ->and($sale->fresh()?->grand_total_minor)->toBe(30000)
            ->and(sessionsLeft($package))->toBe(4);

        app(ApplyPackage::class)->withdraw($sale->fresh() ?? $sale, $owner, $line);

        expect(sessionsLeft($package))->toBe(5)
            ->and($sale->fresh()?->grand_total_minor)->toBe(50000);

        // Three units cannot come off a two-unit line.
        expect(fn () => app(ApplyPackage::class)->apply($sale->fresh() ?? $sale, $owner, $line, $package->uuid, 3, performed: true))
            ->toThrow(PackagesFailed::class, 'That line has only 2 to cover.');
    });
});

it('refuses too few sessions, another customer\'s package, a cancelled or expired one, and a line that is not a service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $other = $this->seedCustomer('Omar Ali', '0750 765 4321');
        $package = $this->paidPackage($seed, $owner, $customer);

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer, 6);

        expect(fn () => app(ApplyPackage::class)->apply($sale, $owner, $line, $package->uuid, 6, performed: true))
            ->toThrow(PackagesFailed::class, 'Only 5 sessions of it are left.');

        [$othersSale, $othersLine] = $this->serviceDraft($seed, $owner, $other);

        expect(fn () => app(ApplyPackage::class)->apply($othersSale, $owner, $othersLine, $package->uuid, 1, performed: true))
            ->toThrow(PackagesFailed::class, 'That package is not this customer\'s.');

        $custom = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($custom, $owner, ['kind' => 'custom', 'name' => 'Consultation', 'unit_price_minor' => 10000, 'reason' => 'Walk-in advice']);
        $customLine = (string) SaleItem::query()->where('sale_id', $custom->getKey())->where('kind', SaleItemKind::Custom->value)->value('uuid');

        expect(fn () => app(ApplyPackage::class)->apply($custom, $owner, $customLine, $package->uuid, 1, performed: true))
            ->toThrow(PackagesFailed::class, 'A package covers a service line.');

        // Valid for 90 days: on day 91 it is expired, and still readable.
        $this->travel(91)->days();
        [$late, $lateLine] = $this->serviceDraft($seed, $owner, $customer);

        expect(fn () => app(ApplyPackage::class)->apply($late, $owner, $lateLine, $package->uuid, 1, performed: true))
            ->toThrow(PackagesFailed::class, 'That package is expired.');

        app(CancelCustomerPackage::class)($package->uuid, $owner, 'Customer asked to close it');

        expect(fn () => app(ApplyPackage::class)->apply($late, $owner, $lateLine, $package->uuid, 1, performed: true))
            ->toThrow(PackagesFailed::class, 'That package is cancelled.');

        // Cancelling forfeits what was left, as history — nothing is deleted.
        expect(sessionsLeft($package))->toBe(0)
            ->and((int) PackageTransaction::query()->where('kind', PackageMovement::Cancellation->value)->sum('quantity'))->toBe(5)
            ->and(PackageTransaction::query()->where('kind', PackageMovement::Allocation->value)->count())->toBe(1);
    });
});

it('gives sessions back when a draft is discarded or a covered sale is voided, and cancels what a voided sale sold', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer, priceMinor: 0);

        [$discarded, $discardedLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyPackage::class)->apply($discarded, $owner, $discardedLine, $package->uuid, 1, performed: true);
        app(CloseSale::class)->discard($discarded->fresh() ?? $discarded, $owner);

        expect(sessionsLeft($package))->toBe(5);

        // Fully covered, so nothing is collected and it can be voided.
        [$covered, $coveredLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyPackage::class)->apply($covered, $owner, $coveredLine, $package->uuid, 1, performed: true);
        app(FinalizeSale::class)($covered->fresh() ?? $covered, $owner);

        expect(sessionsLeft($package))->toBe(4);

        app(CloseSale::class)->void(Sale::query()->findOrFail($covered->getKey()), $owner, 'Rung up twice');

        expect(sessionsLeft($package))->toBe(5)
            ->and(PackageTransaction::query()->where('kind', PackageMovement::Reversal->value)->count())->toBe(2);

        // The (free) sale that SOLD the package is voided: the package ends.
        app(CloseSale::class)->void(Sale::query()->findOrFail($package->sale_id), $owner, 'Sold by mistake');

        expect($package->fresh()?->state(now()))->toBe('cancelled')
            ->and(sessionsLeft($package))->toBe(0);
    });
});

it('lets one benefit cover a line, and keeps honouring paid packages after a downgrade', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPackages();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $package = $this->paidPackage($seed, $owner, $customer);

        $this->revokeEntitlement('packages');

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyPackage::class)->apply($sale, $owner, $line, $package->uuid, 1, performed: true);

        expect(sessionsLeft($package))->toBe(4);

        // A second benefit on the same line is refused by Sales' seam.
        expect(fn () => app(ApplyPackage::class)->apply($sale->fresh() ?? $sale, $owner, $line, $package->uuid, 1, performed: true))
            ->toThrow(SaleFailed::class);

        // Selling a new package is not.
        $next = $this->customerSale($seed['branch'], $owner, $customer);

        expect(fn () => app(AddSaleLine::class)($next, $owner, ['kind' => 'offering', 'offering_type' => 'package', 'offering' => PackageDefinition::query()->value('uuid')]))
            ->toThrow(EntitlementRequired::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
