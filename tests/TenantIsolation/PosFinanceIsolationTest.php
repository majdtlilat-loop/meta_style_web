<?php

declare(strict_types=1);

use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\Actions\RecordExpense;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Application\TillCatalog;
use App\Modules\Sales\Domain\Models\CashierShift;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| The Manager's money reads stay in their center
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md. Sales history, receipts, cashier shifts, expenses and
| POS products are read from the bound center's own database: two centers
| with colliding row ids and invoice numbers each see only their own, and a
| branch uuid from another center is not found.
|
*/

/**
 * One issued, paid invoice, an open shift and a posted expense.
 *
 * @return array{branch: string, invoice: string, number: string, product: string}
 */
function pfiSeed(string $description): array
{
    test()->grantFinance();

    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();
    $invoice = test()->issuedInvoice($seed, $owner);
    app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

    $category = app(ManageExpenseCategory::class)->save(['en' => 'Supplies'], $owner);
    app(RecordExpense::class)->post(['branch' => $seed['branch']->uuid, 'category' => $category->uuid, 'amount_minor' => 4000, 'method' => 'cash', 'description' => $description], $owner);

    $product = test()->seedProduct($description.' product', 9000, null);

    return ['branch' => $seed['branch']->uuid, 'invoice' => $invoice->uuid, 'number' => $invoice->number, 'product' => $product->uuid];
}

it('keeps sales history, receipts, shifts, expenses and products inside the bound center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => pfiSeed('Alpha towels'));
    $b = $this->asCenter($beta['tenant'], fn (): array => pfiSeed('Beta towels'));

    // Same numbering in two databases: only the database tells them apart.
    expect($a['number'])->toBe($b['number']);

    $this->asCenter($alpha['tenant'], function () use ($a, $b): void {
        $owner = $this->ownerWithCatalogAccess();
        $today = $this->branchToday();

        $history = app(SalesQuery::class)->history($owner, $a['branch'], $today, $today);
        $receipts = app(PaymentsQuery::class)->receipts($owner, $a['branch'], $today, $today);
        $expenses = app(FinanceQuery::class)->expensesPage($owner, $a['branch'], $today, $today);
        $shifts = app(SalesQuery::class)->shifts($owner, $a['branch'], $today, $today);
        $products = app(TillCatalog::class)->productsPage();

        expect($history->total())->toBe(1)
            ->and($history->items()[0]->invoice?->uuid)->toBe($a['invoice'])
            ->and($receipts->total())->toBe(1)
            ->and($receipts->items()[0]->invoice?->uuid)->toBe($a['invoice'])
            ->and(array_map(fn ($expense): string => $expense->description, $expenses->items()))->toBe(['Alpha towels'])
            ->and(array_map(fn (CashierShift $shift): int => $shift->branch_id, $shifts))->toHaveCount(1)
            ->and(array_map(fn ($product): string => $product->uuid, $products->items()))->toBe([$a['product']])
            ->and(app(TillCatalog::class)->product($b['product']))->toBeNull();

        // The other center's branch does not exist here.
        expect(fn () => app(SalesQuery::class)->history($owner, $b['branch'], $today, $today))->toThrow(NotFoundHttpException::class)
            ->and(fn () => app(PaymentsQuery::class)->receipts($owner, $b['branch'], $today, $today))->toThrow(NotFoundHttpException::class)
            ->and(fn () => app(FinanceQuery::class)->expensesPage($owner, $b['branch'], $today, $today))->toThrow(NotFoundHttpException::class)
            ->and(fn () => app(SalesQuery::class)->shifts($owner, $b['branch'], $today, $today))->toThrow(NotFoundHttpException::class);

        // The downgrade rule reads this center's own history.
        expect(app(SalesQuery::class)->hasHistory())->toBeTrue()
            ->and(app(PaymentsQuery::class)->hasHistory())->toBeTrue()
            ->and(app(FinanceQuery::class)->hasHistory())->toBeTrue();
    });

    // A third center that never sold anything has no history of its own —
    // two centers' money next door does not unlock its screens.
    $gamma = $this->registerCenter('Spa Gamma', 'owner@gamma.test');

    $this->asCenter($gamma['tenant'], function (): void {
        expect(app(SalesQuery::class)->hasHistory())->toBeFalse()
            ->and(app(PaymentsQuery::class)->hasHistory())->toBeFalse()
            ->and(app(FinanceQuery::class)->hasHistory())->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
