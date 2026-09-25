<?php

declare(strict_types=1);

use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Application\PointsExpiry;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Points expiry
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21.
|
| Every credit carries the expiry it was earned under. Changing the program
| changes what is earned NEXT; points already earned keep their own date. Which
| points are left is derived from the history, oldest credit first, and an
| expired point can never be spent.
|
*/

it('keeps each credit\'s own expiry when the program changes, and uses the new rule only for new points', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['expiry_days' => 90]);
        $customer = $this->seedCustomer();

        $start = CarbonImmutable::now()->utc()->startOfSecond();
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $this->loyaltyProgram($owner, ['expiry_days' => 30]);

        $this->travel(10)->days();
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $earned = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->orderBy('id')->get();

        // The first 20 keep 90 days; the next 20 got 30. Stored on each row —
        // the program's current setting is not what dates a credit.
        expect($earned)->toHaveCount(2)
            ->and($earned[0]->expires_at?->diffInDays($earned[0]->occurred_at, true))->toEqual(90)
            ->and($earned[1]->expires_at?->diffInDays($earned[1]->occurred_at, true))->toEqual(30)
            ->and($earned[0]->expires_at?->greaterThanOrEqualTo($start->addDays(90)))->toBeTrue();

        // Day 45: the 30-day points (earned day 10) have gone; the 90-day ones
        // have not.
        $this->travel(35)->days();

        expect(app(PointsExpiry::class)->due($this->loyaltyAccountOf($customer), CarbonImmutable::now()))->toBe(20);
    });
});

it('spends the oldest valid credits first, so what expires is what was left of the newer ones', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['expiry_days' => 90]);
        $customer = $this->seedCustomer();

        // Day 0: 20 points for 90 days. Day 10: 20 points for 30 days (to day 40).
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $this->loyaltyProgram($owner, ['expiry_days' => 30]);
        $this->travel(10)->days();
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        // Day 20: 25 redeemed — all 20 of the day-0 credit, then 5 of day 10's.
        $this->travel(10)->days();
        $sale = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(RedeemPoints::class)->apply($sale, $owner, 25);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(15);

        // Day 41: day 10's credit has expired with 15 unspent. Spending newest
        // first would have left 15 of the 90-day credit instead.
        $this->travel(21)->days();
        $account = $this->loyaltyAccountOf($customer);

        expect(app(PointsExpiry::class)->due($account, CarbonImmutable::now()))->toBe(15);

        DB::connection('tenant')->transaction(fn () => app(PointsExpiry::class)->apply($account, CarbonImmutable::now()));

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(0)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Expiry->value)->sum('points'))->toEqual(15);

        // Day 91: nothing more to expire — the 90-day credit was already spent.
        $this->travel(50)->days();

        expect(app(PointsExpiry::class)->due($this->loyaltyAccountOf($customer), CarbonImmutable::now()))->toBe(0);
    });
});

it('never lets an expired point be redeemed or taken, and writes each expiry once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['expiry_days' => 30]);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $this->travel(20)->days();
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        // Day 31: the first 20 are gone. The cached balance still says 40
        // until something writes the expiry — and every use does, first.
        $this->travel(11)->days();

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(40);

        $sale = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        expect(fn () => app(RedeemPoints::class)->apply($sale, $owner, 25))
            ->toThrow(LoyaltyFailed::class, 'Only 20 points are available.');

        expect(fn () => app(AdjustPoints::class)($customer->uuid, $owner, PointsDirection::Out, 21, 'Correcting a mistake'))
            ->toThrow(LoyaltyFailed::class, 'Only 20 points are available to take away.');

        // The refusals rolled back with their transactions; the next use
        // writes the expiry, and then only once however often it runs.
        app(RedeemPoints::class)->apply($sale, $owner, 20);
        $account = $this->loyaltyAccountOf($customer);

        DB::connection('tenant')->transaction(fn () => app(PointsExpiry::class)->apply($account, CarbonImmutable::now()));
        DB::connection('tenant')->transaction(fn () => app(PointsExpiry::class)->apply($account, CarbonImmutable::now()));

        expect(LoyaltyTransaction::query()->where('kind', PointsKind::Expiry->value)->count())->toBe(1)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Expiry->value)->value('points'))->toBe(20)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
