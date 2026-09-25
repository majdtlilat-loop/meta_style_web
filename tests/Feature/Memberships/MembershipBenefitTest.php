<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Application\Actions\CancelCustomerMembership;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Memberships\Domain\Models\MembershipBenefitUsage;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\Sale;

/*
|--------------------------------------------------------------------------
| Using a membership at checkout
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§12, 22.
|
| A member's discount lands on the one service line it belongs to, from the
| service's own price — add-ons stay charged. A limited benefit counts its uses
| from the history, per term. Withdrawing it, discarding the draft or voiding
| the sale gives the use back, once. A downgrade stops selling memberships,
| never using one already paid for.
|
*/

/**
 * The only benefit of the customer's only membership.
 */
function onlyBenefit(): CustomerMembershipBenefit
{
    return CustomerMembershipBenefit::query()->sole();
}

it('takes a percentage off the service line from the service price, and gives it back when withdrawn', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, priceMinor: 0));

        // Two haircuts at 20,000, each with a 5,000 add-on: 50,000.
        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer, 2, [$seed['addon']->uuid]);

        app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, onlyBenefit()->uuid);

        // 20% of the SERVICE price (40,000) — the add-ons stay charged.
        expect($sale->fresh()?->discount_total_minor)->toBe(8000)
            ->and($sale->fresh()?->grand_total_minor)->toBe(42000)
            ->and(MembershipBenefitUsage::query()->where('kind', UsageKind::Use->value)->sole()->quantity)->toBe(2);

        app(ApplyMembershipBenefit::class)->withdraw($sale->fresh() ?? $sale, $owner, $line);

        expect($sale->fresh()?->grand_total_minor)->toBe(50000)
            ->and(MembershipBenefitUsage::query()->where('kind', UsageKind::Reversal->value)->sole()->quantity)->toBe(2);
    });
});

it('never takes a fixed benefit past the service price', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, [
            ['service' => null, 'discount_type' => 'fixed', 'amount_minor' => 25000],
        ], priceMinor: 0));

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer, 1, [$seed['addon']->uuid]);

        app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, onlyBenefit()->uuid);

        // 25,000 off a 20,000 service is 20,000; the 5,000 add-on is still due.
        expect($sale->fresh()?->discount_total_minor)->toBe(20000)
            ->and($sale->fresh()?->grand_total_minor)->toBe(5000);
    });
});

it('counts limited uses per term from the history, and a discarded draft gives its use back', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, [
            ['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 10000, 'uses_per_term' => 2],
        ], priceMinor: 0));

        [$first, $firstLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyMembershipBenefit::class)->apply($first, $owner, $firstLine, onlyBenefit()->uuid);

        [$second, $secondLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyMembershipBenefit::class)->apply($second, $owner, $secondLine, onlyBenefit()->uuid);

        [$third, $thirdLine] = $this->serviceDraft($seed, $owner, $customer);

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($third, $owner, $thirdLine, onlyBenefit()->uuid))
            ->toThrow(MembershipsFailed::class, 'Only 0 uses of it are left this term.');

        // A two-unit line needs two uses.
        app(CloseSale::class)->discard($second->fresh() ?? $second, $owner);
        [$double, $doubleLine] = $this->serviceDraft($seed, $owner, $customer, 2);

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($double, $owner, $doubleLine, onlyBenefit()->uuid))
            ->toThrow(MembershipsFailed::class, 'Only 1 uses of it are left this term.');

        // The discarded draft's use came back, exactly once.
        app(ApplyMembershipBenefit::class)->apply($third, $owner, $thirdLine, onlyBenefit()->uuid);

        expect(MembershipBenefitUsage::query()->where('kind', UsageKind::Reversal->value)->count())->toBe(1)
            ->and(MembershipBenefitUsage::query()->where('kind', UsageKind::Use->value)->count())->toBe(3);
    });
});

it('gives a use back when the sale that used it is voided', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, [
            ['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 10000, 'uses_per_term' => 1],
        ], priceMinor: 0));

        // Free with the membership, so nothing is collected and it can be voided.
        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, onlyBenefit()->uuid);
        app(FinalizeSale::class)($sale->fresh() ?? $sale, $owner);

        app(CloseSale::class)->void(Sale::query()->findOrFail($sale->getKey()), $owner, 'Wrong customer');

        expect(MembershipBenefitUsage::query()->where('kind', UsageKind::Reversal->value)->count())->toBe(1);

        // The use is available again.
        [$next, $nextLine] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyMembershipBenefit::class)->apply($next, $owner, $nextLine, onlyBenefit()->uuid);

        expect($next->fresh()?->grand_total_minor)->toBe(0);
    });
});

it('refuses another customer\'s, a cancelled, an expired and a not-yet-started membership', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $this->outlastTrial();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $member = $this->seedCustomer();
        $other = $this->seedCustomer('Omar Ali', '0750 765 4321');
        $plan = $this->membershipPlan($seed, $owner, priceMinor: 0);

        $this->membershipInvoice($seed['branch'], $owner, $member, $plan);
        $this->membershipInvoice($seed['branch'], $owner, $member, $plan);

        [$current, $renewal] = CustomerMembership::query()->orderBy('id')->get()->all();
        $currentBenefit = $current->benefits()->sole()->uuid;
        $renewalBenefit = $renewal->benefits()->sole()->uuid;

        [$othersSale, $othersLine] = $this->serviceDraft($seed, $owner, $other);

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($othersSale, $owner, $othersLine, $currentBenefit))
            ->toThrow(MembershipsFailed::class, 'That membership is not this customer\'s.');

        [$sale, $line] = $this->serviceDraft($seed, $owner, $member);

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, $renewalBenefit))
            ->toThrow(MembershipsFailed::class, 'That membership is upcoming.');

        // A month on, the first term is over and the renewal has begun.
        $this->travel(31)->days();
        [$later, $laterLine] = $this->serviceDraft($seed, $owner, $member);

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($later, $owner, $laterLine, $currentBenefit))
            ->toThrow(MembershipsFailed::class, 'That membership is expired.');

        app(CancelCustomerMembership::class)($renewal->uuid, $owner, 'Customer moved away');

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($later, $owner, $laterLine, $renewalBenefit))
            ->toThrow(MembershipsFailed::class, 'That membership is cancelled.');
    });
});

it('stops selling memberships after a downgrade, but keeps honouring the ones paid for', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $plan = $this->membershipPlan($seed, $owner);

        $invoice = $this->membershipInvoice($seed['branch'], $owner, $customer, $plan);
        $this->revokeEntitlement('memberships');

        // Paid after the downgrade: the customer still gets what they paid for.
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 50000);

        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);
        app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, onlyBenefit()->uuid);

        expect($sale->fresh()?->discount_total_minor)->toBe(4000);

        // Selling a new one is refused.
        $next = $this->customerSale($seed['branch'], $owner, $customer);

        expect(fn () => app(AddSaleLine::class)($next, $owner, ['kind' => 'offering', 'offering_type' => 'membership', 'offering' => $plan->uuid]))
            ->toThrow(EntitlementRequired::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
