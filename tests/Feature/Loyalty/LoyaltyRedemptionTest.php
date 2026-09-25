<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyCompleted;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Redeeming, adjusting and visit rewards
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§5, 7, 21–22.
|
| Points are redeemed explicitly, at checkout, on a DRAFT, as a benefit
| adjustment Sales re-prices — never against a published invoice. Withdrawing
| it, discarding the draft or voiding the sale gives the points back, once. A
| completed visit earns its reward once, however often its completion is heard.
| A hand adjustment needs its own permission and a reason.
|
*/

it('redeems points as a sale discount, and withdrawing, discarding or voiding gives them back once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000]);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        [$sale] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($sale, $owner, 5);

        expect($sale->fresh()?->discount_total_minor)->toBe(5000)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(15);

        app(RedeemPoints::class)->withdraw($sale->fresh() ?? $sale, $owner);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            ->and($sale->fresh()?->discount_total_minor)->toBe(0);

        app(RedeemPoints::class)->apply($sale->fresh() ?? $sale, $owner, 5);
        app(CloseSale::class)->discard($sale->fresh() ?? $sale, $owner);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20);

        // All 20 points pay the whole sale, so nothing is collected and it can be voided.
        [$paidByPoints] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($paidByPoints, $owner, 20);
        app(FinalizeSale::class)($paidByPoints->fresh() ?? $paidByPoints, $owner);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(0);

        app(CloseSale::class)->void(Sale::query()->findOrFail($paidByPoints->getKey()), $owner, 'Rung up for the wrong customer');

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Redeem->value)->count())->toBe(3)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(3);
    });
});

it('never redeems against a published invoice, below the minimum, beyond what is left to pay, or twice on one sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000, 'min_redeem_points' => 5]);
        $customer = $this->seedCustomer();

        $invoice = $this->customerInvoice($seed, $owner, $customer);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(40);

        // Published: Sales refuses before loyalty is touched.
        expect(fn () => app(RedeemPoints::class)->apply(Sale::query()->findOrFail($invoice->sale_id), $owner, 5))
            ->toThrow(SaleFailed::class);

        [$sale] = $this->serviceDraft($seed, $owner, $customer);

        expect(fn () => app(RedeemPoints::class)->apply($sale, $owner, 3))
            ->toThrow(LoyaltyFailed::class, 'Redeem at least 5 points.')
            ->and(fn () => app(RedeemPoints::class)->apply($sale, $owner, 21))
            ->toThrow(LoyaltyFailed::class, 'worth more than is left to pay');

        app(RedeemPoints::class)->apply($sale, $owner, 10);

        expect(fn () => app(RedeemPoints::class)->apply($sale->fresh() ?? $sale, $owner, 5))
            ->toThrow(LoyaltyFailed::class, 'Points are already redeemed on this sale.');

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(30);
    });
});

it('stops new earning and redemption after a downgrade, yet keeps history readable and corrections flowing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['point_value_minor' => 1000]);
        $customer = $this->seedCustomer();

        $payment = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        [$sale] = $this->serviceDraft($seed, $owner, $customer);
        app(RedeemPoints::class)->apply($sale, $owner, 5);

        $this->revokeEntitlement('loyalty');

        // New money earns nothing; new redemptions are refused.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        [$another] = $this->serviceDraft($seed, $owner, $customer);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(15)
            ->and(fn () => app(RedeemPoints::class)->apply($another, $owner, 5))->toThrow(EntitlementRequired::class);

        // Giving points back, and taking back what a refund no longer earns, still work.
        app(RedeemPoints::class)->withdraw($sale->fresh() ?? $sale, $owner);
        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 10000, 'Half refunded');

        $read = app(LoyaltyQuery::class)->forCustomer($customer->uuid, $owner)['account'];

        expect($read?->balance)->toBe(10)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(2);

        // Loyalty is bought again: the money collected while it was off never
        // earns — not on replay either. Money collected from now on does.
        $this->travel(5)->seconds();
        $this->grantLoyalty();

        expect(app(LoyaltySync::class)->reconcile(CarbonImmutable::now()->subDay()))->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(10);

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(30);
    });
});

it('rewards a completed visit once, however often its completion is heard', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['spend_points' => 0, 'visit_points' => 3]);
        $customer = $this->seedCustomer();

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

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(3);

        // The same completion heard again — a retry, a duplicate event.
        Event::dispatch(new JourneyCompleted((int) $journey->getKey(), $journey->branchId()));
        Event::dispatch(new JourneyCompleted((int) $journey->getKey(), $journey->branchId()));

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(3)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->count())->toBe(1)
            ->and(app(LoyaltySync::class)->reconcile(CarbonImmutable::now()->subDay()))->toBe(0);
    });
});

it('adjusts points only with loyalty.adjust and a reason, append-only and audited', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();
        $viewer = $this->staffWith([Permission::LoyaltyView]);

        expect(fn () => app(AdjustPoints::class)($customer->uuid, $viewer, PointsDirection::In, 10, 'Goodwill'))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(AdjustPoints::class)($customer->uuid, $owner, PointsDirection::In, 10, 'no'))
            ->toThrow(LoyaltyFailed::class, 'needs a reason')
            ->and(fn () => app(AdjustPoints::class)($customer->uuid, $owner, PointsDirection::Out, 1, 'Nothing to take'))
            ->toThrow(LoyaltyFailed::class, 'Only 0 points are available to take away.');

        $row = app(AdjustPoints::class)($customer->uuid, $owner, PointsDirection::In, 10, 'Goodwill for a late start');

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(10)
            ->and($row->reason)->toBe('Goodwill for a late start')
            ->and($row->actor_label)->toBe($owner->name)
            ->and(DB::connection('tenant')->table('audit_logs')->where('action', 'loyalty.points_adjusted')->count())->toBe(1)
            // History is never edited: a correction is a new row.
            ->and(fn () => $row->forceFill(['points' => 99])->save())->toThrow(LogicException::class)
            ->and(fn () => $row->delete())->toThrow(LogicException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
