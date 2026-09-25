<?php

declare(strict_types=1);

use App\Modules\Loyalty\Application\Actions\ManageLoyaltyTier;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Application\TierResolver;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\AddSaleLine;

/*
|--------------------------------------------------------------------------
| Points a refund could not take back
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §8.
|
| A refund reverses the FULL earning of what it refunded. When the customer
| already spent part of it, the balance covers what it can — never going below
| zero — and the rest is recorded as unrecovered and settled, append-only, from
| the customer's next earnings. Tiers are measured on what was earned and KEPT.
|
*/

/**
 * Σ in − Σ out, from the history alone — what the cached balance must equal.
 */
function pointsFromHistory(LoyaltyAccount $account): int
{
    $rows = LoyaltyTransaction::query()->where('loyalty_account_id', $account->getKey())->get();

    return (int) $rows->where('direction', PointsDirection::In)->sum('points')
        - (int) $rows->where('direction', PointsDirection::Out)->sum('points');
}

it('reverses the full earning of a refund, records what the balance could not cover, and settles it from later earnings', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        // Earn 20, spend 15 of them on another visit.
        $first = $this->customerInvoice($seed, $owner, $customer);
        $payment = app(CollectDeskPayment::class)($first, $owner, PaymentMethod::Cash, 20000);

        $sale = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(RedeemPoints::class)->apply($sale, $owner, 15);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(5);

        // The first visit is refunded in full: its 20 points go. Only 5 are
        // left to take — the other 15 are recorded, not hidden, not negative.
        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 20000, 'Service not delivered');

        /** @var LoyaltyTransaction $reversal */
        $reversal = LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->sole();
        $account = $this->loyaltyAccountOf($customer);

        expect($reversal->points)->toBe(5)
            ->and($reversal->unrecovered_points)->toBe(15)
            ->and($account?->balance)->toBe(0)
            ->and($account?->unrecovered_points)->toBe(15)
            // Lifetime keeps none of the refunded earning — covered or not.
            ->and($account?->lifetime_points)->toBe(0);

        // A later visit, paid in two halves. The first half earns 10 — all of
        // it settles the shortfall, and none of it is spendable yet.
        $later = $this->customerInvoice($seed, $owner, $customer);
        app(CollectDeskPayment::class)($later, $owner, PaymentMethod::Cash, 10000);
        $account = $this->loyaltyAccountOf($customer);

        expect($account?->balance)->toBe(0)
            ->and($account?->unrecovered_points)->toBe(5)
            ->and($account?->lifetime_points)->toBe(10);

        // The second half earns 10 more: 5 settle the rest, 5 are the customer's.
        app(CollectDeskPayment::class)($later, $owner, PaymentMethod::Cash, 10000);
        $account = $this->loyaltyAccountOf($customer);

        expect($account?->balance)->toBe(5)
            ->and($account?->unrecovered_points)->toBe(0)
            ->and($account?->lifetime_points)->toBe(20);

        // Every settlement is its own row, keyed on the earning it came from.
        $recoveries = LoyaltyTransaction::query()->where('kind', PointsKind::Recovery->value)->orderBy('id')->get();

        expect($recoveries->pluck('points')->all())->toBe([10, 5])
            ->and($recoveries->every(static fn (LoyaltyTransaction $row): bool => $row->source_type === PointsSource::Recovery
                && LoyaltyTransaction::query()->where('uuid', $row->source_uuid)->where('kind', PointsKind::Earn->value)->exists()))->toBeTrue()
            ->and(pointsFromHistory($account))->toBe($account->balance)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Recovery->value)->sum('points')
                + (int) $account->unrecovered_points)->toEqual(15);

        // Settled, so a further earning is all the customer's.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(25)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Recovery->value)->count())->toBe(2);
    });
});

it('never takes the balance below zero, whatever order refunds and redemptions arrive in', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        $a = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $b = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $sale = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(RedeemPoints::class)->apply($sale, $owner, 35);

        // Balance 5; two refunds reverse 20 and then 20 more.
        app(RequestRefund::class)($a, $owner, PaymentMethod::Cash, 20000, 'Refund one');
        app(RequestRefund::class)($b, $owner, PaymentMethod::Cash, 20000, 'Refund two');

        $account = $this->loyaltyAccountOf($customer);

        expect($account?->balance)->toBe(0)
            ->and($account?->unrecovered_points)->toBe(35)
            ->and($account?->lifetime_points)->toBe(0)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->orderBy('id')->pluck('points')->all())->toBe([5, 0])
            ->and(pointsFromHistory($account))->toBe(0);
    });
});

it('drops a customer from a tier when the earning that reached it is refunded, even when the points were spent', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        app(ManageLoyaltyTier::class)->save($owner, ['en' => 'Silver'], 20);
        $customer = $this->seedCustomer();

        $payment = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect(app(TierResolver::class)->tierFor((int) $this->loyaltyAccountOf($customer)?->lifetime_points)?->name->get('en'))->toBe('Silver');

        // The points are spent, then the money goes back.
        $sale = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(RedeemPoints::class)->apply($sale, $owner, 20);
        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 20000, 'Refunded in full');

        $account = $this->loyaltyAccountOf($customer);

        expect($account?->lifetime_points)->toBe(0)
            ->and($account?->unrecovered_points)->toBe(20)
            ->and(app(TierResolver::class)->tierFor((int) $account?->lifetime_points))->toBeNull();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
