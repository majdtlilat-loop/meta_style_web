<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\CashierShifts;
use App\Livewire\Center\Sales as SalesScreen;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Sales history and cashier shifts
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §45 · docs/20-FINANCE.md §§31–33, 42. A sale is found by
| invoice number, customer or cashier over whole BRANCH-LOCAL days; a typed
| date is refused, never handed to Carbon; a supervisor closes a colleague's
| shift with a blind count.
|
*/

/**
 * @return array{0: Sale, 1: Sale}
 */
function shIssueTwo(array $seed, $owner): array
{
    test()->openShift($seed['branch'], $owner);

    $customer = app(SaveCustomer::class)(new CustomerInput(name: 'Layla Hassan'), $owner);

    $first = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($first, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    app(AdjustSale::class)->customer($first, $owner, $customer->uuid);
    app(FinalizeSale::class)($first->fresh() ?? $first, $owner);

    $second = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($second, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    app(FinalizeSale::class)($second, $owner);

    return [$first->fresh() ?? $first, $second->fresh() ?? $second];
}

it('finds sales by invoice number, customer and cashier over branch-local days', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter('Asia/Baghdad');
        $owner = $this->ownerWithCatalogAccess();
        [$layla, $walkUp] = shIssueTwo($seed, $owner);

        $query = app(SalesQuery::class);
        $today = BranchClock::localDate(CarbonImmutable::now()->utc(), 'Asia/Baghdad');
        $number = (string) $layla->invoice?->number;

        $all = $query->history($owner, $seed['branch']->uuid, $today, $today);
        $byNumber = $query->history($owner, $seed['branch']->uuid, $today, $today, ['term' => substr($number, -6)]);
        $byCustomer = $query->history($owner, $seed['branch']->uuid, $today, $today, ['term' => 'Layla']);
        $byCashier = $query->history($owner, $seed['branch']->uuid, $today, $today, ['cashier' => $owner->uuid]);
        $voided = $query->history($owner, $seed['branch']->uuid, $today, $today, ['status' => 'voided']);

        expect($all->total())->toBe(2)
            ->and(array_map(fn (Sale $sale): string => $sale->uuid, $byNumber->items()))->toBe([$layla->uuid])
            ->and(array_map(fn (Sale $sale): string => $sale->uuid, $byCustomer->items()))->toBe([$layla->uuid])
            ->and($byCashier->total())->toBe(2)
            ->and($voided->total())->toBe(0)
            ->and($query->cashiers($owner, $seed['branch']->uuid, $today, $today))->toBe([['id' => $owner->uuid, 'name' => $owner->name]]);

        $totals = $query->historyTotals($owner, $seed['branch']->uuid, $today, $today);

        expect($totals)->toBe([['status' => 'finalized', 'currency' => 'IQD', 'sales' => 2, 'total_minor' => 40000]]);

        // 00:30 in Baghdad is 21:30 UTC the day before: it belongs to the
        // Baghdad day, never to the UTC one.
        $walkUp->forceFill(['finalized_at' => BranchClock::toUtc($today, 30, 'Asia/Baghdad')])->save();
        $yesterday = CarbonImmutable::parse($today)->subDay()->format('Y-m-d');

        expect($query->history($owner, $seed['branch']->uuid, $today, $today)->total())->toBe(2)
            ->and($query->history($owner, $seed['branch']->uuid, $yesterday, $yesterday)->total())->toBe(0);

        // A typed date is refused as a message, never an exception from Carbon.
        expect(fn () => $query->history($owner, $seed['branch']->uuid, '2026-13-45', $today))->toThrow(SaleFailed::class, 'Dates are YYYY-MM-DD.')
            ->and(fn () => $query->forBranch($owner, $seed['branch']->uuid, null, 'abc'))->toThrow(SaleFailed::class, 'Dates are YYYY-MM-DD.')
            ->and(fn () => $query->history($owner, $seed['branch']->uuid, '2026-01-01', '2026-12-31'))->toThrow(SaleFailed::class, 'Choose a range of at most 92 days.');

        // Someone without the permission reads nothing.
        $stranger = $this->staffWith([Permission::CustomerView], 'stranger@alpha.test');
        expect(fn () => $query->history($stranger, $seed['branch']->uuid, $today, $today))->toThrow(AuthorizationException::class);

        // The screen: a tampered window falls back to today; the drawer opens
        // from ?sale= and shows the discount, cashier and local issue time.
        $this->actingAs($owner, 'web');

        Livewire::withQueryParams(['range' => 'custom', 'from' => 'abc', 'until' => '2026-99-99', 'sale' => $layla->uuid])
            ->test(SalesScreen::class)
            ->assertSee($number)
            ->assertSee('Layla Hassan')
            ->set('term', 'Layla')
            ->assertViewHas('sales', fn (array $rows): bool => count($rows) === 1 && $rows[0]['uuid'] === $layla->uuid);
    });
});

it('lets a supervisor close a cashier\'s shift with a blind count, and a cashier see only their own', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $cashier = $this->staffWith([Permission::CashierShiftManage, Permission::SaleView], 'cashier@alpha.test');
        $colleague = $this->staffWith([Permission::CashierShiftManage], 'colleague@alpha.test');
        $shift = app(ManageCashierShift::class)->open($seed['branch']->uuid, $cashier, null, null, 10000);
        app(ManageCashierShift::class)->open($seed['branch']->uuid, $colleague);

        // A cashier sees their own shift, never a colleague's.
        $this->actingAs($cashier, 'web');

        Livewire::test(CashierShifts::class)
            ->assertViewHas('open', fn (array $open): bool => array_column($open, 'uuid') === [$shift->uuid]);

        // The supervisor sees both, closes the cashier's with a count — and the
        // expected figure never appears before the count is entered.
        $this->actingAs($owner, 'web');

        $screen = Livewire::test(CashierShifts::class)
            ->assertViewHas('open', fn (array $open): bool => count($open) === 2)
            ->call('startClose', $shift->uuid)
            ->assertDontSee(__('Expected'))
            ->call('close')
            ->assertSet('error', 'Count the cash in the drawer before closing the shift.')
            ->set('countedCash', '9500')
            ->call('close')
            ->assertSet('error', '')
            ->assertSet('closing', '');

        $count = ShiftReconciliation::query()->sole();

        expect($count->variance_minor)->toBe(-500)
            ->and($shift->fresh()?->closed_at)->not->toBeNull()
            ->and($shift->fresh()?->closed_by_label)->toBe($owner->name);

        $screen->assertViewHas('closed', fn (array $closed): bool => $closed[0]['counted']['label'] === 'Short' && $closed[0]['closed_by'] === $owner->name);

        // Without either shift permission: the page says so and reads nothing.
        $this->actingAs($this->staffWith([Permission::SaleView], 'viewer@alpha.test'), 'web');

        Livewire::test(CashierShifts::class)
            ->assertViewHas('denied', true)
            ->assertViewHas('open', []);

        expect(CashierShift::query()->whereNotNull('active_user_id')->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
