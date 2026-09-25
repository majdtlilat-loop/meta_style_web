<?php

declare(strict_types=1);

use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\InvoiceRenderer;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Query budgets
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §53.
|
| A cart, an invoice and a day's sales list must cost the same number of queries
| at three rows as at twelve. Asserted as "does not grow", not as a magic number:
| a presenter that lazy-loads one relation per line fails here the day it is
| written, rather than on a Saturday with a real till.
|
*/

function qcSeedSales(): array
{
    return test()->seedBookableCenter();
}

function qcCountQueries(callable $work): int
{
    $count = 0;

    DB::connection('tenant')->listen(function () use (&$count): void {
        $count++;
    });

    $work();

    return $count;
}

function qcCart(array $seed, $owner, int $lines): Sale
{
    $sale = test()->draftSale($seed['branch'], $owner);

    for ($i = 0; $i < $lines; $i++) {
        app(AddSaleLine::class)($sale, $owner, [
            'kind' => 'service',
            'service' => $seed['service']->uuid,
            'addons' => [$seed['addon']->uuid],
        ]);
    }

    return $sale;
}

it('loads and presents a cart in a fixed number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeedSales();
        $owner = $this->ownerWithCatalogAccess();

        $small = qcCart($seed, $owner, 3);
        $large = qcCart($seed, $owner, 12);

        $present = fn (Sale $sale) => fn () => app(SalesPresenter::class)->sale(app(SalesQuery::class)->find($sale->uuid, $owner), $owner);

        // Warm anything cached per request (permissions, entitlements).
        $present($small)();

        $forSmall = qcCountQueries($present($small));
        $forLarge = qcCountQueries($present($large));

        expect($forLarge)->toBe($forSmall)
            ->and($forSmall)->toBeLessThanOrEqual(10);
    });
});

it('renders a finalized invoice in a fixed number of queries, without touching the catalog', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeedSales();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $small = app(FinalizeSale::class)(qcCart($seed, $owner, 3), $owner)->invoice;
        $large = app(FinalizeSale::class)(qcCart($seed, $owner, 12), $owner)->invoice;

        $render = fn (Invoice $invoice) => fn () => app(InvoiceRenderer::class)->document(
            Invoice::query()->whereKey($invoice->id)->firstOrFail(),
            'en',
            SaleStatus::Finalized,
        );

        // Warm anything cached per request (permissions, entitlements, the
        // center's content locales a translated name falls back to) — the
        // same warm-up the cart test does; what is counted is what grows.
        $render($small)();

        $statements = [];

        DB::connection('tenant')->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $forSmall = qcCountQueries($render($small));
        $forLarge = qcCountQueries($render($large));

        expect($forLarge)->toBe($forSmall)
            ->and($forSmall)->toBeLessThanOrEqual(3);

        // A historical document is never recomputed from today's catalog.
        foreach ($statements as $sql) {
            expect($sql)->not->toContain('`services`')
                ->and($sql)->not->toContain('`service_addons`')
                ->and($sql)->not->toContain('`products`')
                ->and($sql)->not->toContain('`sale_items`');
        }
    });
});

it('lists a day of sales in a fixed number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeedSales();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $list = fn () => array_map(
            fn (Sale $sale): array => app(SalesPresenter::class)->sale($sale, $owner, withLines: false),
            app(SalesQuery::class)->forBranch($owner, $seed['branch']->uuid),
        );

        for ($i = 0; $i < 3; $i++) {
            $customer = $this->seedCustomer('Customer '.$i, '0750 000 00'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $sale = qcCart($seed, $owner, 1);
            app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);
            app(FinalizeSale::class)($sale, $owner);
        }

        $list();
        $forThree = qcCountQueries($list);

        for ($i = 3; $i < 12; $i++) {
            $customer = $this->seedCustomer('Customer '.$i, '0750 000 00'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $sale = qcCart($seed, $owner, 1);
            app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);
            app(FinalizeSale::class)($sale, $owner);
        }

        $forTwelve = qcCountQueries($list);

        expect($forTwelve)->toBe($forThree)
            ->and(count($list()))->toBe(12)
            ->and(count(app(SalesQuery::class)->forBranch($owner, $seed['branch']->uuid, null, null, 1000)))->toBe(12);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
