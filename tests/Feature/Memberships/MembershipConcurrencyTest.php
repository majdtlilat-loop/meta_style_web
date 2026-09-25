<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Application\ActivateMemberships;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\MembershipBenefitUsage;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Two desks, one membership
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§24–25.
|
| The customer's membership row is the lock every use takes after the sale's,
| so a limited benefit can never be used past its limit by two desks at once.
| The same payment heard twice activates one membership.
|
*/

it('makes a second use of the last limited use wait for the first, then refuses it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner, [
            ['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 10000, 'uses_per_term' => 1],
        ], priceMinor: 0));

        /** @var CustomerMembership $membership */
        $membership = CustomerMembership::query()->sole();
        $benefit = $membership->benefits()->sole();
        [$sale, $line] = $this->serviceDraft($seed, $owner, $customer);

        [$otherDesk, $release] = secondTenantConnection('tenant_membership_desk');

        try {
            $otherDesk->beginTransaction();
            $otherDesk->table('customer_memberships')->where('id', $membership->getKey())->lockForUpdate()->get();

            $waited = waitsForTenantLock(fn () => app(ApplyMembershipBenefit::class)->apply($sale, $owner, $line, $benefit->uuid));

            expect($waited)->toBeTrue()
                ->and(MembershipBenefitUsage::query()->count())->toBe(0);

            // Desk A uses the only use and commits.
            $otherDesk->table('membership_benefit_usages')->insert([
                'uuid' => (string) Str::uuid(),
                'customer_membership_id' => $membership->getKey(),
                'customer_membership_benefit_id' => $benefit->getKey(),
                'kind' => 'use',
                'quantity' => 1,
                'source_type' => 'benefit',
                'source_uuid' => (string) Str::uuid(),
                'occurred_at' => now()->utc(),
                'created_at' => now()->utc(),
            ]);
            $otherDesk->commit();
        } finally {
            $release();
        }

        expect(fn () => app(ApplyMembershipBenefit::class)->apply($sale->fresh() ?? $sale, $owner, $line, $benefit->uuid))
            ->toThrow(MembershipsFailed::class, 'Only 0 uses of it are left this term.');

        expect(MembershipBenefitUsage::query()->where('kind', UsageKind::Use->value)->count())->toBe(1);
    });
});

it('activates one membership from a payment heard twice, and waits for a worker already activating it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $invoice = $this->membershipInvoice($seed['branch'], $owner, $customer, $this->membershipPlan($seed, $owner));

        [$otherDesk, $release] = secondTenantConnection('tenant_activation_worker');

        try {
            $otherDesk->beginTransaction();
            $otherDesk->table('sales')->where('id', $invoice->sale_id)->lockForUpdate()->get();

            expect(waitsForTenantLock(fn () => app(ActivateMemberships::class)->syncSale($invoice->sale_id)))->toBeTrue();
        } finally {
            $release();
        }

        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 50000);

        foreach ([1, 2] as $_) {
            DB::connection('tenant')->transaction(fn () => Event::dispatch(new PaymentSucceeded((int) $payment->getKey())));
        }

        expect(CustomerMembership::query()->count())->toBe(1)
            ->and(app(ActivateMemberships::class)->reconcile(now()->utc()->subDay()->toImmutable()))->toBe(0);
    });
});

/**
 * Makes activation fail while `$down` is true, so a paid renewal can be left
 * settled but not yet activated — the state two workers would race over.
 */
function renewalStoreDown(bool &$down): void
{
    CustomerMembership::creating(static function () use (&$down): void {
        if ($down) {
            throw new RuntimeException('Membership storage unavailable');
        }
    });
}

it('serializes two renewals of the same membership into consecutive, non-overlapping terms', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantMemberships();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();
        $plan = $this->membershipPlan($seed, $owner);

        // The running term.
        app(CollectDeskPayment::class)($this->membershipInvoice($seed['branch'], $owner, $customer, $plan), $owner, PaymentMethod::Cash, 50000);

        /** @var CustomerMembership $current */
        $current = CustomerMembership::query()->sole();

        // Two renewals, both paid, neither activated yet: exactly what two
        // workers would pick up at the same moment.
        Exceptions::fake([AfterCommitFailed::class]);
        $down = true;
        renewalStoreDown($down);

        $first = $this->membershipInvoice($seed['branch'], $owner, $customer, $plan);
        app(CollectDeskPayment::class)($first, $owner, PaymentMethod::Cash, 50000);

        $second = $this->membershipInvoice($seed['branch'], $owner, $customer, $plan);
        app(CollectDeskPayment::class)($second, $owner, PaymentMethod::Cash, 50000);

        $down = false;

        expect(CustomerMembership::query()->count())->toBe(1);

        [$otherDesk, $release] = secondTenantConnection('tenant_renewal_worker');

        try {
            // One worker is mid-activation: it holds the CUSTOMER row, which is
            // the lock that makes renewals stack one after another.
            $otherDesk->beginTransaction();
            $otherDesk->table('customers')->where('id', $customer->getKey())->lockForUpdate()->get();

            expect(waitsForTenantLock(fn () => app(ActivateMemberships::class)->syncSale($first->sale_id)))->toBeTrue()
                ->and(CustomerMembership::query()->count())->toBe(1);
        } finally {
            $release();
        }

        // Serialized: one, then the other.
        app(ActivateMemberships::class)->syncSale($first->sale_id);
        app(ActivateMemberships::class)->syncSale($second->sale_id);

        /** @var list<CustomerMembership> $terms */
        $terms = CustomerMembership::query()->orderBy('starts_at')->orderBy('id')->get()->all();

        expect($terms)->toHaveCount(3)
            ->and($terms[0]->uuid)->toBe($current->uuid);

        // Consecutive and non-overlapping: each starts exactly where the last ended.
        for ($i = 1; $i < count($terms); $i++) {
            expect($terms[$i]->starts_at->equalTo($terms[$i - 1]->expires_at))->toBeTrue()
                ->and($terms[$i]->expires_at->greaterThan($terms[$i]->starts_at))->toBeTrue();
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
