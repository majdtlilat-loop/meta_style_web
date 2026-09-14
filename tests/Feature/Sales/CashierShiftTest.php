<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Cashier shifts
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§13–14, 45, 49.
|
| An operational period, not a cash drawer. It attributes every finalized sale
| to a person and a window — the seam Phase 10 reconciliation will count
| against — and nothing more.
|
| Invariant: ONE OPEN SHIFT PER USER PER BRANCH, backed by a lock and a unique
| index.
|
*/

function csSeed(): array
{
    return test()->seedBookableCenter();
}

it('opens a shift once, however many times it is opened', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = csSeed();
        $owner = $this->ownerWithCatalogAccess();

        $first = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner, 'Float counted');
        $second = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);

        expect($second->uuid)->toBe($first->uuid)
            ->and(CashierShift::query()->count())->toBe(1)
            ->and($first->status)->toBe(ShiftStatus::Open)
            ->and($first->active_user_id)->toBe($owner->id)
            ->and(app(ManageCashierShift::class)->currentFor($owner, $seed['branch']->id)?->uuid)->toBe($first->uuid);
    });
});

it('backs the one-open-shift invariant with the database', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = csSeed();
        $owner = $this->ownerWithCatalogAccess();

        app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);

        expect(fn () => DB::connection('tenant')->table('cashier_shifts')->insert([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $seed['branch']->id,
            'user_id' => $owner->id,
            'active_user_id' => $owner->id,
            'status' => 'open',
            'opened_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('closes a shift, and a closed shift lets a new one open', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = csSeed();
        $owner = $this->ownerWithCatalogAccess();

        $shift = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);
        $closed = app(ManageCashierShift::class)->close($shift, $owner, 'End of day');

        expect($closed->status)->toBe(ShiftStatus::Closed)
            ->and($closed->closed_at)->not->toBeNull()
            ->and($closed->active_user_id)->toBeNull()
            ->and($closed->closing_note)->toBe('End of day')
            ->and(app(ManageCashierShift::class)->currentFor($owner, $seed['branch']->id))->toBeNull();

        // Closing again is the same outcome.
        expect(app(ManageCashierShift::class)->close($shift, $owner)->closing_note)->toBe('End of day');

        $next = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);

        expect($next->uuid)->not->toBe($shift->uuid)
            ->and(CashierShift::query()->count())->toBe(2);
    });
});

it('lets one person hold open shifts at two different branches', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = csSeed();
        $owner = $this->ownerWithCatalogAccess();
        $second = $this->seedBranch('Mansour');

        $here = app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner);
        $there = app(ManageCashierShift::class)->open($second->uuid, $owner);

        expect($here->uuid)->not->toBe($there->uuid)
            ->and(CashierShift::query()->where('status', 'open')->count())->toBe(2);
    });
});

it('lets a cashier close their own shift and only a supervisor close someone else\'s', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = csSeed();
        $owner = $this->ownerWithCatalogAccess();

        $cashier = $this->staffWith([Permission::CashierShiftManage, Permission::SaleView], 'till@alpha.test');
        $colleague = $this->staffWith([Permission::CashierShiftManage], 'till2@alpha.test');

        $shift = app(ManageCashierShift::class)->open($seed['branch']->uuid, $cashier);

        expect(fn () => app(ManageCashierShift::class)->close($shift, $colleague))
            ->toThrow(AuthorizationException::class);

        // The owner holds `cashier_shift.supervise` and closes it on their behalf.
        $closed = app(ManageCashierShift::class)->close($shift, $owner, 'Forgot to close');

        expect($closed->status)->toBe(ShiftStatus::Closed)
            ->and($closed->closed_by_id)->toBe($owner->uuid);
    });
});

it('attributes finalized sales to the shift and summarises them without voids', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = csSeed();
        $owner = $this->ownerWithCatalogAccess();

        $shift = $this->openShift($seed['branch'], $owner);

        $sales = [];

        for ($i = 0; $i < 3; $i++) {
            $sale = $this->draftSale($seed['branch'], $owner);
            app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
            app(FinalizeSale::class)($sale, $owner);
            $sales[] = $sale;
        }

        app(CloseSale::class)->void($sales[0], $owner, 'Duplicate');

        $summary = app(SalesQuery::class)->shiftSummary($shift);

        expect($sales[1]->fresh()?->cashier_shift_id)->toBe($shift->id)
            ->and($summary['sales'])->toBe(2)
            ->and($summary['voided'])->toBe(1)
            ->and($summary['totals'])->toBe([['currency' => 'IQD', 'grand_total_minor' => 40000]]);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
