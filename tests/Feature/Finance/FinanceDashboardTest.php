<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Livewire\Center\FinanceOverview;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\FinanceDashboard;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\CloseSale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Center finance, kept honest
|--------------------------------------------------------------------------
|
| docs/20-FINANCE.md §§42–44, 72.
|
| INVOICED is what was billed. COLLECTED, REFUNDED and EXPENSES are money that
| moved. None of them is "revenue", and an unpaid invoice is never money.
|
*/

/** The main branch's local date: the dashboard counts days where the branch is. */
function fdToday(): string
{
    return test()->branchToday();
}

it('keeps billed, collected, refunded, expenses and net movement as separate numbers', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $paid = $this->issuedInvoice($seed, $owner);          // 20,000, fully paid in cash
        $unpaid = $this->issuedInvoice($seed, $owner);        // 20,000, nothing paid
        $partial = $this->issuedInvoice($seed, $owner);       // 20,000, 5,000 by transfer
        $voided = $this->issuedInvoice($seed, $owner);        // 20,000, voided

        $cash = app(CollectDeskPayment::class)($paid, $owner, PaymentMethod::Cash, 20000);
        app(CollectDeskPayment::class)($partial, $owner, PaymentMethod::ManualElectronic, 5000, 'Bank transfer');
        app(RequestRefund::class)($cash, $owner, PaymentMethod::Cash, 3000, 'Partly redone');
        app(CloseSale::class)->void($voided->sale()->firstOrFail(), $owner, 'Wrong customer');

        $category = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
        app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 4000, 'method' => 'cash', 'description' => 'Towels'], $owner);

        $summary = app(FinanceDashboard::class)->summary($owner, fdToday(), fdToday());

        expect($summary['invoiced_minor'])->toBe(60000)           // three live invoices
            ->and($summary['invoice_count'])->toBe(3)
            ->and($summary['voided_minor'])->toBe(20000)          // shown apart, never billed
            ->and($summary['collected_minor'])->toBe(25000)
            ->and($summary['collected_by_method'])->toBe(['cash' => 20000, 'manual_electronic' => 5000, 'gateway' => 0])
            ->and($summary['refunded_minor'])->toBe(3000)
            ->and($summary['net_expenses_minor'])->toBe(4000)
            ->and($summary['net_movement_minor'])->toBe(18000)    // 25,000 − 3,000 − 4,000
            // Owed on live invoices: 0 + 20,000 + 15,000. The refund reopened nothing.
            ->and($summary['outstanding_minor'])->toBe(35000);

        $this->actingAs($owner, 'web');

        Livewire::test(FinanceOverview::class)
            ->assertSet('error', '')
            ->assertSee('Invoiced')
            ->assertSee('Collected')
            ->assertSee('Net money movement')
            ->assertDontSee('Revenue');
    });
});

it('filters by branch, and refuses a window longer than the bound', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $invoice = $this->issuedInvoice($seed, $owner);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        $other = $this->seedBranch('Mansour');

        $main = app(FinanceDashboard::class)->summary($owner, fdToday(), fdToday(), $seed['branch']->uuid);
        $mansour = app(FinanceDashboard::class)->summary($owner, fdToday(), fdToday(), $other->uuid);

        expect($main['collected_minor'])->toBe(20000)
            ->and($mansour['collected_minor'])->toBe(0)
            ->and($mansour['invoiced_minor'])->toBe(0);

        expect(fn () => app(FinanceDashboard::class)->summary($owner, CarbonImmutable::parse(fdToday())->subDays(92)->format('Y-m-d'), fdToday()))
            ->toThrow(FinanceFailed::class, 'at most 92 days');
    });
});

it('costs the same few queries for three sales as for twelve', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantFinance();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $count = function () use ($owner): int {
            $queries = 0;

            // The listener outlives this call; only what runs before `return` counts.
            DB::connection('tenant')->listen(function () use (&$queries): void {
                $queries++;
            });

            app(FinanceDashboard::class)->summary($owner, fdToday(), fdToday());

            return $queries;
        };

        for ($i = 0; $i < 3; $i++) {
            app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::Cash, 20000);
        }

        // Warm anything cached per request (permissions, entitlements, the
        // center's content locales a branch name falls back to), so the two
        // counts compare what grows with the sales, not a one-time read.
        app(FinanceDashboard::class)->summary($owner, fdToday(), fdToday());

        $forThree = $count();

        for ($i = 0; $i < 9; $i++) {
            app(CollectDeskPayment::class)($this->issuedInvoice($seed, $owner), $owner, PaymentMethod::Cash, 20000);
        }

        $forTwelve = $count();

        expect($forTwelve)->toBe($forThree)
            ->and($forThree)->toBeLessThanOrEqual(10);
    });
});

it('needs Finance for the dashboard', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        expect(fn () => app(FinanceDashboard::class)->summary($owner, fdToday(), fdToday()))
            ->toThrow(EntitlementRequired::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
