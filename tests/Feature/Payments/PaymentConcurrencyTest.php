<?php

declare(strict_types=1);

use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Two desks, one balance
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§10–11, 17, 65–66.
|
| A genuinely separate MySQL connection holds the lock another desk would hold;
| the real Action runs with a one-second lock timeout. "It waited, and wrote
| nothing while waiting" is observable that way — then the same call runs and
| the rules decide.
|
*/

/**
 * @return array{0: Connection, 1: callable(): void}
 */
function pcOtherDesk(): array
{
    /** @var array<string, mixed> $config */
    $config = config('database.connections.tenant');

    config(['database.connections.tenant_payment_desk' => $config]);

    $desk = DB::connection('tenant_payment_desk');

    return [$desk, static function () use ($desk): void {
        if ($desk->transactionLevel() > 0) {
            $desk->rollBack();
        }

        $desk->disconnect();
        DB::purge('tenant_payment_desk');
    }];
}

function pcWaitedOnLock(callable $work): bool
{
    $tenant = DB::connection('tenant');
    $tenant->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $work();

        return false;
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '1205') || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    } finally {
        $tenant->statement('SET SESSION innodb_lock_wait_timeout = 50');
    }
}

it('makes a second desk collecting the last balance wait for the first, then refuses it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);

        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 15000);

        [$otherDesk, $release] = pcOtherDesk();

        try {
            // Desk A is mid-collection of the last 5,000: it holds the invoice's sale row.
            $otherDesk->beginTransaction();
            $otherDesk->table('sales')->where('id', $invoice->sale_id)->lockForUpdate()->get();

            $waited = pcWaitedOnLock(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 5000));

            expect($waited)->toBeTrue()
                ->and(Payment::query()->count())->toBe(1);

            // Desk A commits its 5,000.
            $otherDesk->table('payments')->insert([
                'uuid' => (string) Str::uuid(),
                'invoice_id' => $invoice->id,
                'branch_id' => $invoice->branch_id,
                'amount_minor' => 5000,
                'currency' => 'IQD',
                'method' => 'cash',
                'status' => 'succeeded',
                'source' => 'desk',
                'initiated_at' => now(),
                'succeeded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $otherDesk->commit();
        } finally {
            $release();
        }

        // Desk B retries against the committed world: nothing is left.
        expect(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 5000))
            ->toThrow(PaymentFailed::class, 'more than is left');

        expect((int) Payment::query()->where('invoice_id', $invoice->id)->where('status', 'succeeded')->sum('amount_minor'))->toBe(20000);
    });
});

it('makes a second refund on the same payment wait for the first, and never lets them exceed it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        [$otherDesk, $release] = pcOtherDesk();

        try {
            // Manager A is refunding 15,000: holds the payment row.
            $otherDesk->beginTransaction();
            $otherDesk->table('payments')->where('id', $payment->id)->lockForUpdate()->get();

            $waited = pcWaitedOnLock(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 15000, 'Second manager'));

            expect($waited)->toBeTrue()
                ->and(Refund::query()->count())->toBe(0);

            $otherDesk->table('refunds')->insert([
                'uuid' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'branch_id' => $payment->branch_id,
                'amount_minor' => 15000,
                'currency' => 'IQD',
                'method' => 'manual_electronic',
                'status' => 'succeeded',
                'reason' => 'First manager',
                'requested_at' => now(),
                'succeeded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $otherDesk->commit();
        } finally {
            $release();
        }

        expect(fn () => app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 15000, 'Second manager'))
            ->toThrow(PaymentFailed::class, 'more than is left to refund');

        expect((int) Refund::query()->where('payment_id', $payment->id)->sum('amount_minor'))->toBe(15000);
    });
});

it('makes a cash collection wait for a shift close that holds the drawer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $invoice = $this->issuedInvoice($seed, $owner);
        $shift = CashierShift::query()->where('active_user_id', $owner->id)->firstOrFail();

        [$otherDesk, $release] = pcOtherDesk();

        try {
            // The drawer is being counted and closed.
            $otherDesk->beginTransaction();
            $otherDesk->table('cashier_shifts')->where('id', $shift->id)->lockForUpdate()->get();

            $waited = pcWaitedOnLock(fn () => app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 1000));

            expect($waited)->toBeTrue()
                ->and(Payment::query()->count())->toBe(0);

            $otherDesk->rollBack();
        } finally {
            $release();
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
