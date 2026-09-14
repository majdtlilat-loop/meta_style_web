<?php

declare(strict_types=1);

use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Two tills at once
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§27, 47–49.
|
| These run the REAL Actions against a lock held by a genuinely separate MySQL
| connection — the database, not a mock, decides who waits. The Action under
| test runs with a one-second lock timeout, so "it waited on the lock" shows up
| as a lock-wait failure that rolled back every write, and then the same call
| succeeds once the other desk has committed.
|
| A PHP process cannot race itself, so this is how a lock is proved to exist
| AND to protect the path that matters: a finalization that did not take the
| sale lock, or allocated the number before taking it, would not wait here.
|
*/

function ccSeed(): array
{
    return test()->seedBookableCenter();
}

/**
 * A second desk: its own connection to the same tenant database.
 *
 * @return array{0: Connection, 1: callable(): void}
 */
function ccOtherDesk(): array
{
    /** @var array<string, mixed> $config */
    $config = config('database.connections.tenant');

    config(['database.connections.tenant_other_desk' => $config]);

    $desk = DB::connection('tenant_other_desk');

    $release = static function () use ($desk): void {
        if ($desk->transactionLevel() > 0) {
            $desk->rollBack();
        }

        $desk->disconnect();
        DB::purge('tenant_other_desk');
    };

    return [$desk, $release];
}

/**
 * Runs `$work` on THIS desk with a short lock timeout, returning whether it was
 * refused for waiting on a lock.
 */
function ccWaitedOnLock(callable $work): bool
{
    $tenant = DB::connection('tenant');
    $tenant->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $work();

        return false;
    } catch (QueryException $e) {
        // 1205: lock wait timeout exceeded.
        return str_contains($e->getMessage(), '1205') || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    } finally {
        $tenant->statement('SET SESSION innodb_lock_wait_timeout = 50');
    }
}

it('makes a second desk finalizing the same sale wait, then hands it the one invoice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ccSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        [$otherDesk, $release] = ccOtherDesk();

        try {
            // Desk one is mid-finalization: it holds the sale row.
            $otherDesk->beginTransaction();
            $otherDesk->table('sales')->where('id', $sale->id)->lockForUpdate()->get();

            // Desk two presses finalize on the same sale.
            $waited = ccWaitedOnLock(fn () => app(FinalizeSale::class)($sale, $owner));

            expect($waited)->toBeTrue()
                // Nothing was published and no number consumed while it waited.
                ->and(Invoice::query()->count())->toBe(0)
                ->and(DB::connection('tenant')->table('invoice_sequences')->count())->toBe(0);

            $otherDesk->rollBack();
        } finally {
            $release();
        }

        // Desk one's lock is gone; both desks now finalize the same sale.
        $first = app(FinalizeSale::class)($sale, $owner);
        $second = app(FinalizeSale::class)($sale->fresh() ?? $sale, $owner);

        expect($second->invoice->uuid)->toBe($first->invoice->uuid)
            ->and(Invoice::query()->where('sale_id', $sale->id)->count())->toBe(1)
            ->and($sale->fresh()?->status)->toBe(SaleStatus::Finalized)
            ->and((int) DB::connection('tenant')->table('invoice_sequences')->value('last_number'))->toBe(1);
    });
});

it('serialises two different sales on the branch sequence so their numbers never collide', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ccSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);
        $year = now()->year;

        $saleA = $this->draftSale($seed['branch'], $owner);
        $saleB = $this->draftSale($seed['branch'], $owner);

        foreach ([$saleA, $saleB] as $sale) {
            app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        }

        [$otherDesk, $release] = ccOtherDesk();

        try {
            // Desk one is finalizing sale A and has allocated this branch-year's
            // next number — holding the sequence row, not yet committed.
            $otherDesk->beginTransaction();
            $otherDesk->table('invoice_sequences')->insertOrIgnore([
                'branch_id' => $seed['branch']->id,
                'sequence_year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $otherDesk->table('invoice_sequences')
                ->where('branch_id', $seed['branch']->id)
                ->where('sequence_year', $year)
                ->lockForUpdate()
                ->get();
            $otherDesk->table('invoice_sequences')
                ->where('branch_id', $seed['branch']->id)
                ->where('sequence_year', $year)
                ->update(['last_number' => 1]);

            // Desk two finalizes a DIFFERENT sale at the same moment. It must
            // wait for the sequence, or it would read the same number.
            $waited = ccWaitedOnLock(fn () => app(FinalizeSale::class)($saleB, $owner));

            expect($waited)->toBeTrue()
                ->and(Invoice::query()->count())->toBe(0)
                ->and($saleB->fresh()?->status)->toBe(SaleStatus::Draft);

            // Desk one commits its allocation.
            $otherDesk->commit();
        } finally {
            $release();
        }

        // Desk two retries and continues from the committed value.
        $invoiceB = app(FinalizeSale::class)($saleB, $owner)->invoice;

        expect($invoiceB->sequence_number)->toBe(2)
            ->and($invoiceB->number)->toBe("INV-{$year}-000002");
    });
});

it('makes a second tap on "open shift" wait for the first, then return it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ccSeed();
        $owner = $this->ownerWithCatalogAccess();

        [$otherDesk, $release] = ccOtherDesk();

        try {
            // Tap one is mid-open: it holds this person's user row.
            $otherDesk->beginTransaction();
            $otherDesk->table('users')->where('id', $owner->id)->lockForUpdate()->get();

            $waited = ccWaitedOnLock(fn () => app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner));

            expect($waited)->toBeTrue()
                ->and(CashierShift::query()->count())->toBe(0);

            $otherDesk->rollBack();
        } finally {
            $release();
        }

        $first = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);
        $second = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);

        expect($second->uuid)->toBe($first->uuid)
            ->and(CashierShift::query()->count())->toBe(1);
    });
});

it('makes a shift close wait behind a finalization that is using it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ccSeed();
        $owner = $this->ownerWithCatalogAccess();
        $shift = $this->openShift($seed['branch'], $owner);

        [$otherDesk, $release] = ccOtherDesk();

        try {
            // A finalization in flight holds the shift row it is stamping.
            $otherDesk->beginTransaction();
            $otherDesk->table('cashier_shifts')->where('id', $shift->id)->lockForUpdate()->get();

            $waited = ccWaitedOnLock(fn () => app(ManageCashierShift::class)->close($shift, $owner));

            expect($waited)->toBeTrue()
                ->and($shift->fresh()?->isOpen())->toBeTrue();

            $otherDesk->rollBack();
        } finally {
            $release();
        }

        expect(app(ManageCashierShift::class)->close($shift, $owner)->isOpen())->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
