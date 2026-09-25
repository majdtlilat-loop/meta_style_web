<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyEarningObservation;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A repair reproduces what should have happened
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6.
|
| Reconciliation only decides WHEN a missing row is written. What it is worth,
| when it is dated and when it expires come from the moment the money was
| collected or the visit completed: the rule version effective then, and whether
| the center owned `loyalty` then.
|
*/

/**
 * Makes every points write fail while `$down` is true. Observations and rule
 * versions are other tables, so they keep being written — exactly as they
 * would if only the points ledger were unavailable.
 */
function pointsStoreDown(bool &$down): void
{
    LoyaltyTransaction::creating(static function () use (&$down): void {
        if ($down) {
            throw new RuntimeException('Loyalty storage unavailable');
        }
    });
}

function sinceLongAgo(): CarbonImmutable
{
    return CarbonImmutable::now()->utc()->subDays(365);
}

it('repairs an earning under the rule in force when the money was collected, not the rule now', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        // Rule A: one point per 1,000, expiring after 90 days.
        $this->loyaltyProgram($owner, ['spend_points' => 1, 'spend_unit_minor' => 1000, 'expiry_days' => 90]);

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        pointsStoreDown($down);

        $payment = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $collectedAt = CarbonImmutable::instance($payment->fresh()?->succeeded_at ?? CarbonImmutable::now());

        $down = false;
        expect(LoyaltyTransaction::query()->count())->toBe(0);

        // Ten days later the manager doubles the rate and shortens expiry.
        $this->travel(10)->days();
        $this->loyaltyProgram($owner, ['spend_points' => 2, 'spend_unit_minor' => 1000, 'expiry_days' => 30]);

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(1);

        /** @var LoyaltyTransaction $earn */
        $earn = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->sole();

        expect($earn->points)->toBe(20)
            ->and($earn->occurred_at->equalTo($collectedAt))->toBeTrue()
            ->and($earn->expires_at?->equalTo($collectedAt->addDays(90)))->toBeTrue()
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            // Running it again writes nothing.
            ->and(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(0);

        // Money collected from now on earns under the new rule.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(60);
    });
});

it('repairs a visit reward under the visit rule in force when the visit was completed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $this->loyaltyProgram($owner, ['spend_points' => 0, 'visit_points' => 3, 'expiry_days' => 90]);

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        pointsStoreDown($down);

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

        $completedAt = CarbonImmutable::instance($journey->fresh()?->completed_at ?? CarbonImmutable::now());
        $down = false;

        expect(LoyaltyTransaction::query()->count())->toBe(0);

        $this->travel(14)->days();
        $this->loyaltyProgram($owner, ['spend_points' => 0, 'visit_points' => 7, 'expiry_days' => 30]);

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(1);

        /** @var LoyaltyTransaction $earn */
        $earn = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->sole();

        expect($earn->points)->toBe(3)
            ->and($earn->occurred_at->equalTo($completedAt))->toBeTrue()
            ->and($earn->expires_at?->equalTo($completedAt->addDays(90)))->toBeTrue();
    });
});

it('keeps money collected while loyalty was owned recoverable after the entitlement is taken away', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        pointsStoreDown($down);

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $down = false;
        $this->travel(5)->seconds();
        $this->revokeEntitlement('loyalty');

        $sync = app(LoyaltySync::class);

        // It was earned while the center owned loyalty: it is still owed.
        expect($sync->reconcile(sinceLongAgo()))->toBe(1)
            ->and($sync->reconcile(sinceLongAgo()))->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(20);
    });
});

it('never earns for money collected during a gap, even after loyalty is granted again', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        $this->travel(5)->seconds();
        $this->revokeEntitlement('loyalty');
        $this->travel(5)->seconds();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $this->travel(5)->seconds();
        $this->grantLoyalty();

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(0)
            ->and(LoyaltyTransaction::query()->count())->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance ?? 0)->toBe(0);
    });
});

it('awards only what was collected inside the owned periods, across a gap', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        pointsStoreDown($down);

        // A — owned.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $this->travel(5)->seconds();
        $this->revokeEntitlement('loyalty');
        $this->travel(5)->seconds();

        // B — during the gap.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $this->travel(5)->seconds();
        $this->grantLoyalty();
        $this->travel(5)->seconds();

        // C — owned again.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $down = false;

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(2)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->count())->toBe(2)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(40);
    });
});

it('places an event whose own observation never happened on the timeline around it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['spend_points' => 1, 'spend_unit_minor' => 1000, 'expiry_days' => 90]);
        $customer = $this->seedCustomer();

        // The process dies between the commit and the callback: no listener
        // runs at all, so this payment has no observation of its own.
        Event::fake([PaymentSucceeded::class]);
        $payment = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect(LoyaltyEarningObservation::query()->where('source_type', PointsSource::Payment->value)->count())->toBe(0)
            ->and(LoyaltyTransaction::query()->count())->toBe(0);

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(1);

        /** @var LoyaltyTransaction $earn */
        $earn = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->sole();
        $collectedAt = CarbonImmutable::instance($payment->fresh()?->succeeded_at ?? CarbonImmutable::now());

        expect($earn->points)->toBe(20)
            ->and($earn->occurred_at->equalTo($collectedAt))->toBeTrue()
            ->and($earn->expires_at?->equalTo($collectedAt->addDays(90)))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| The evidence can be gone, and the repair is still right
|--------------------------------------------------------------------------
|
| Observations are evidence, not the record. The DURABLE source is the
| append-only rule history: the payment's own timestamp, the version effective
| at it, and that version's expiry. These wipe the observation table entirely —
| a harder loss than the one it is there for — and still require the event-time
| answer.
|
*/

/**
 * Loses every observation, as if that table had never been written.
 */
function forgetObservations(): void
{
    DB::connection('tenant')->table('loyalty_earning_observations')->delete();
}

it('reconstructs a payment under the rule effective at it with no observation left at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        // Rule A.
        $this->loyaltyProgram($owner, ['spend_points' => 1, 'spend_unit_minor' => 1000, 'expiry_days' => 90]);

        // The process dies right after the money commits: neither the earning
        // nor the observation of this payment is ever written.
        Event::fake([PaymentSucceeded::class]);
        $payment = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $collectedAt = CarbonImmutable::instance($payment->fresh()?->succeeded_at ?? CarbonImmutable::now());

        // Rule B, then the entitlement goes away.
        $this->travel(10)->days();
        $this->loyaltyProgram($owner, ['spend_points' => 2, 'spend_unit_minor' => 1000, 'expiry_days' => 30]);
        $this->revokeEntitlement('loyalty');

        // And the whole observation table is lost.
        forgetObservations();

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(1);

        /** @var LoyaltyTransaction $earn */
        $earn = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->sole();

        // Rule A's points, Rule A's expiry, the payment's own date.
        expect($earn->points)->toBe(20)
            ->and($earn->occurred_at->equalTo($collectedAt))->toBeTrue()
            ->and($earn->expires_at?->equalTo($collectedAt->addDays(90)))->toBeTrue()
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            // And it is still written exactly once.
            ->and(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(0)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->count())->toBe(1);
    });
});

it('reconstructs a visit reward under the rule effective at it with no observation left at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $this->loyaltyProgram($owner, ['spend_points' => 0, 'visit_points' => 3, 'expiry_days' => 90]);

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        pointsStoreDown($down);

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

        $completedAt = CarbonImmutable::instance($journey->fresh()?->completed_at ?? CarbonImmutable::now());
        $down = false;

        $this->travel(14)->days();
        $this->loyaltyProgram($owner, ['spend_points' => 0, 'visit_points' => 7, 'expiry_days' => 30]);
        $this->revokeEntitlement('loyalty');

        forgetObservations();

        expect(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(1);

        /** @var LoyaltyTransaction $earn */
        $earn = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->sole();

        expect($earn->points)->toBe(3)
            ->and($earn->occurred_at->equalTo($completedAt))->toBeTrue()
            ->and($earn->expires_at?->equalTo($completedAt->addDays(90)))->toBeTrue()
            ->and(app(LoyaltySync::class)->reconcile(sinceLongAgo()))->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
