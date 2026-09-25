<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Kernel\Time\BranchClock;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Memberships\Application\Actions\ManageMembershipPlan;
use App\Modules\Memberships\Application\ActivateMemberships;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| Membership activation
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§1, 10–11.
|
| A membership becomes the customer's when the invoice that sold it is settled
| — after that payment commits, never as part of it — as a snapshot of the plan.
| A renewal starts when the running term ends. A failure cannot roll the payment
| back, and what it left undone is activated from the facts, exactly once.
|
*/

/**
 * Makes every membership activation fail while `$down` is true.
 */
function membershipStoreDown(bool &$down): void
{
    CustomerMembership::creating(static function () use (&$down): void {
        if ($down) {
            throw new RuntimeException('Membership storage unavailable');
        }
    });
}

it('activates a snapshot of the plan only once the invoice that sold it is settled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $plan = $this->membershipPlan($seed, $owner);
        $invoice = $this->membershipInvoice($seed['branch'], $owner, $customer, $plan);

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 25000);

        expect(CustomerMembership::query()->count())->toBe(0);

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 25000);

        /** @var CustomerMembership $membership */
        $membership = CustomerMembership::query()->sole();
        $expected = BranchClock::localDayStartAfter(CarbonImmutable::instance($membership->starts_at), 30, $seed['branch']->timezone);

        expect($membership->customer_id)->toBe((int) $customer->getKey())
            ->and($membership->price_minor)->toBe(50000)
            ->and($membership->state(CarbonImmutable::now()))->toBe('active')
            ->and($membership->expires_at->equalTo($expected))->toBeTrue()
            ->and(CustomerMembershipBenefit::query()->sole()->basis_points)->toBe(2000);

        // Editing the plan changes what is sold next — not what was bought.
        app(ManageMembershipPlan::class)->save($owner, ['en' => 'Gold'], 90000, 60, [
            ['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 1000],
        ], plan: $plan);

        expect(CustomerMembershipBenefit::query()->sole()->basis_points)->toBe(2000)
            ->and($membership->fresh()?->duration_days)->toBe(30)
            ->and($membership->fresh()?->price_minor)->toBe(50000);
    });
});

it('activates a free membership at finalization, and refuses to sell one without a customer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $plan = $this->membershipPlan($seed, $owner, priceMinor: 0);

        $this->membershipInvoice($seed['branch'], $owner, $customer, $plan);

        expect(CustomerMembership::query()->count())->toBe(1);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'offering', 'offering_type' => 'membership', 'offering' => $plan->uuid]);

        expect(fn () => app(FinalizeSale::class)($sale, $owner))
            ->toThrow(SaleFailed::class, 'Attach the customer before finalizing.');
    });
});

it('never lets a failing activation roll back or fail the payment, and reconciliation activates it exactly once', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $invoice = $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner));

        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        membershipStoreDown($down);

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 50000);

        expect(Payment::query()->whereKey($payment->id)->value('status'))->toBe(PaymentStatus::Succeeded)
            ->and(FinanceEntry::query()->where('kind', EntryKind::Collection->value)->count())->toBe(1)
            ->and(app(InvoiceSettlement::class)->forInvoice($invoice)->state)->toBe(SettlementState::Paid)
            ->and(CustomerMembership::query()->count())->toBe(0);

        Exceptions::assertReported(static fn (AfterCommitFailed $e): bool => $e->label === 'memberships.activate_on_payment');

        $down = false;

        // Reading writes nothing — not even a repair.
        expect(app(MembershipsQuery::class)->forCustomer($customer->uuid, $owner)['memberships'])->toBe([])
            ->and(CustomerMembership::query()->count())->toBe(0);

        $activation = app(ActivateMemberships::class);
        $since = CarbonImmutable::now()->subDay();

        expect($activation->reconcile($since))->toBe(1)
            ->and($activation->reconcile($since))->toBe(0)
            ->and(CustomerMembership::query()->count())->toBe(1)
            ->and(CustomerMembershipBenefit::query()->count())->toBe(1);
    });
});

it('starts a renewal when the running term ends, never overlapping it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $plan = $this->membershipPlan($seed, $owner);

        app(CollectDeskPayment::class)($this->membershipInvoice($seed['branch'], $owner, $customer, $plan), $owner, PaymentMethod::Cash, 50000);
        app(CollectDeskPayment::class)($this->membershipInvoice($seed['branch'], $owner, $customer, $plan), $owner, PaymentMethod::Cash, 50000);

        [$current, $renewal] = CustomerMembership::query()->orderBy('id')->get()->all();
        $now = CarbonImmutable::now();

        expect($renewal->starts_at->equalTo($current->expires_at))->toBeTrue()
            ->and($renewal->expires_at->equalTo(BranchClock::localDayStartAfter(CarbonImmutable::instance($current->expires_at), 30, $seed['branch']->timezone)))->toBeTrue()
            ->and($current->state($now))->toBe('active')
            ->and($renewal->state($now))->toBe('upcoming')
            ->and($renewal->isUsable($now))->toBeFalse()
            // The day the first term ends, the renewal is the one in force.
            ->and($renewal->isUsable(CarbonImmutable::instance($current->expires_at)))->toBeTrue()
            ->and($current->isUsable(CarbonImmutable::instance($current->expires_at)))->toBeFalse();
    });
});

it('cancels what a voided sale sold, and keeps its history', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $invoice = $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, priceMinor: 0));

        /** @var Sale $sale */
        $sale = Sale::query()->findOrFail($invoice->sale_id);
        app(CloseSale::class)->void($sale, $owner, 'Sold by mistake');

        /** @var CustomerMembership $membership */
        $membership = CustomerMembership::query()->sole();

        expect($membership->state(CarbonImmutable::now()))->toBe('cancelled')
            ->and($membership->cancel_reason)->toBe('The sale that sold it was voided')
            ->and(CustomerMembershipBenefit::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
