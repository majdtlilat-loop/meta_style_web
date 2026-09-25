<?php

declare(strict_types=1);

use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Two desks, one points balance
|--------------------------------------------------------------------------
|
| docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§24–25.
|
| A genuinely separate connection holds the account lock another desk would
| hold. The redemption waits for it, then decides on the committed balance — so
| two desks can never spend the same points. The same payment heard twice earns
| once, and the database itself refuses a second row for one source.
|
*/

it('makes a second redemption of the same points wait for the first, then refuses what is no longer there', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);
        $account = $this->loyaltyAccountOf($customer);
        [$sale] = $this->serviceDraft($seed, $owner, $customer);

        [$otherDesk, $release] = secondTenantConnection('tenant_loyalty_desk');

        try {
            // Desk A is mid-redemption: it holds the customer's account row.
            $otherDesk->beginTransaction();
            $otherDesk->table('loyalty_accounts')->where('id', $account?->getKey())->lockForUpdate()->get();

            $waited = waitsForTenantLock(fn () => app(RedeemPoints::class)->apply($sale, $owner, 20));

            expect($waited)->toBeTrue()
                ->and(LoyaltyTransaction::query()->where('kind', PointsKind::Redeem->value)->count())->toBe(0);

            // Desk A spends all 20 and commits.
            $otherDesk->table('loyalty_transactions')->insert([
                'uuid' => (string) Str::uuid(),
                'loyalty_account_id' => $account?->getKey(),
                'kind' => 'redeem',
                'direction' => 'out',
                'points' => 20,
                'unrecovered_points' => 0,
                'source_type' => 'benefit',
                'source_uuid' => (string) Str::uuid(),
                'occurred_at' => now()->utc(),
                'created_at' => now()->utc(),
            ]);
            $otherDesk->table('loyalty_accounts')->where('id', $account?->getKey())->update(['balance' => 0]);
            $otherDesk->commit();
        } finally {
            $release();
        }

        // Desk B decides on the committed balance.
        expect(fn () => app(RedeemPoints::class)->apply($sale->fresh() ?? $sale, $owner, 20))
            ->toThrow(LoyaltyFailed::class, 'Only 0 points are available.');

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(0);
    });
});

it('earns once from a payment heard twice, and the database refuses a second row for one source', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantLoyalty();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->loyaltyProgram($owner);
        $customer = $this->seedCustomer();

        $payment = app(CollectDeskPayment::class)($this->customerInvoice($seed, $owner, $customer), $owner, PaymentMethod::Cash, 20000);

        // Heard again, twice — as a redelivered event is, inside a transaction
        // (the ledger's own listener insists on one).
        foreach ([1, 2] as $_) {
            DB::connection('tenant')->transaction(fn () => Event::dispatch(new PaymentSucceeded((int) $payment->getKey())));
        }

        /** @var LoyaltyTransaction $earn */
        $earn = LoyaltyTransaction::query()->where('kind', PointsKind::Earn->value)->sole();

        expect($this->loyaltyAccountOf($customer)?->balance)->toBe(20);

        // Even a writer that skipped every check could not add a second earning
        // for the same payment: (source_type, source_uuid, kind) is unique.
        expect(fn () => LoyaltyTransaction::query()->insert([
            'uuid' => (string) Str::uuid(),
            'loyalty_account_id' => $earn->loyalty_account_id,
            'kind' => 'earn',
            'direction' => 'in',
            'points' => 20,
            'unrecovered_points' => 0,
            'source_type' => 'payment',
            'source_uuid' => $earn->source_uuid,
            'occurred_at' => now()->utc(),
            'created_at' => now()->utc(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
