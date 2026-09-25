<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Loyalty\Application\Actions\AdjustPoints;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| Loyalty after the money commits — and its reconciliation
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §1.
|
| Points are a consequence of money, never part of it. A loyalty failure must
| not roll back, or turn into an error, a payment or refund that really
| happened; and whatever it left undone is replayed from the payments
| themselves, exactly once.
|
*/

/**
 * Makes every loyalty write fail while `$down` is true — a store that is
 * unavailable, from the model's own event, with no test double in the code.
 */
function loyaltyStoreDown(bool &$down): void
{
    LoyaltyTransaction::creating(static function () use (&$down): void {
        if ($down) {
            throw new RuntimeException('Loyalty storage unavailable');
        }
    });
}

it('never lets a failing loyalty reaction roll back or fail the payment, and reconciliation repairs it exactly once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();
        $invoice = $this->customerInvoice($seed, $owner, $customer);

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        loyaltyStoreDown($down);

        // No exception reaches the desk: the money moved, and says so.
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        expect(Payment::query()->whereKey($payment->id)->value('status'))->toBe(PaymentStatus::Succeeded)
            ->and(FinanceEntry::query()->where('kind', EntryKind::Collection->value)->count())->toBe(1)
            ->and(app(InvoiceSettlement::class)->forInvoice($invoice)->state)->toBe(SettlementState::Paid)
            ->and(LoyaltyTransaction::query()->count())->toBe(0)
            ->and($this->loyaltyAccountOf($customer)?->balance ?? 0)->toBe(0);

        Exceptions::assertReported(static fn (AfterCommitFailed $e): bool => $e->label === 'loyalty.earn_on_payment');

        $down = false;
        $sync = app(LoyaltySync::class);
        $since = CarbonImmutable::now()->subDay();

        // Repaired from the payment, once; the second run finds nothing.
        expect($sync->reconcile($since))->toBe(1)
            ->and($sync->reconcile($since))->toBe(0)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->count())->toBe(1)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(20)
            ->and($this->loyaltyAccountOf($customer)?->lifetime_points)->toBe(20);
    });
});

it('never lets a failing reversal fail the refund, and the scheduled command replays it exactly once', function (): void {
    $center = $this->registerCenter();

    [$customer] = $this->asCenter($center['tenant'], function (): array {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();
        $invoice = $this->customerInvoice($seed, $owner, $customer);
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20);

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        loyaltyStoreDown($down);

        $refund = app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 10000, 'Half the service redone');

        expect(Refund::query()->whereKey($refund->id)->value('status'))->toBe(RefundStatus::Succeeded)
            ->and(FinanceEntry::query()->where('kind', EntryKind::Refund->value)->count())->toBe(1)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(20);

        Exceptions::assertReported(static fn (AfterCommitFailed $e): bool => $e->label === 'loyalty.reverse_on_refund');

        // The store is back before the scheduler next runs.
        $down = false;

        return [$customer];
    });

    // Outside any tenant, as the scheduler runs it.
    $this->artisan('metastyle:reconcile', ['--tenant' => $center['tenant']->id])
        ->expectsOutputToContain('1 item(s) repaired.')
        ->assertSuccessful();

    $this->artisan('metastyle:reconcile', ['--tenant' => $center['tenant']->id])
        ->expectsOutputToContain('0 item(s) repaired.')
        ->assertSuccessful();

    $this->asCenter($center['tenant'], function () use ($customer): void {
        expect(LoyaltyTransaction::query()->where('kind', PointsKind::Reversal->value)->count())->toBe(1)
            ->and($this->loyaltyAccountOf($customer)?->balance)->toBe(10)
            ->and($this->loyaltyAccountOf($customer)?->lifetime_points)->toBe(10);
    });
});

it('keeps reads read-only, and syncs what a lost callback left unearned before points are redeemed or adjusted', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        loyaltyStoreDown($down);
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $down = false;

        // Reading writes nothing — not even a repair. The account is simply
        // not there yet; the scheduled reconcile is what repairs it.
        expect(app(LoyaltyQuery::class)->forCustomer($customer->uuid, $owner)['account'])->toBeNull()
            ->and(app(LoyaltyQuery::class)->accountOf((int) $customer->getKey()))->toBeNull()
            ->and(LoyaltyAccount::query()->count())->toBe(0)
            ->and(LoyaltyTransaction::query()->count())->toBe(0);

        // Redeeming changes the balance, so it syncs the customer first and
        // decides on the 20 points the payment really earned.
        $sale = $this->customerSale($seed['branch'], $owner, $customer);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(RedeemPoints::class)->apply($sale, $owner, 20);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(0)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->count())->toBe(1);

        // So does a manual adjustment.
        $down = true;
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $down = false;

        app(AdjustPoints::class)($customer->uuid, $owner, PointsDirection::Out, 20, 'Duplicate visit reversed');

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(0)
            ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->count())->toBe(2)
            ->and(app(LoyaltySync::class)->reconcile(CarbonImmutable::now()->subDay()))->toBe(0);
    });
});

it('never awards points on replay for money collected while earning was switched off', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner, ['spend_points' => 0]);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        $this->travel(5)->minutes();
        $this->loyaltyProgram($owner, ['spend_points' => 1]);

        // The payment has no loyalty row, so the replay looks at it — and
        // leaves it alone: it was collected before the rule existed.
        expect(app(LoyaltySync::class)->reconcile(CarbonImmutable::now()->subDay()))->toBe(0)
            ->and(LoyaltyTransaction::query()->count())->toBe(0);

        // Money collected from now on earns.
        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
