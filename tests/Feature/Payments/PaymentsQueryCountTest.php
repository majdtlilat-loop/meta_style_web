<?php

declare(strict_types=1);

use App\Livewire\Center\InvoicePayments;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Application\Actions\RequestRefund;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Payments query budgets
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §63.
|
| An invoice with two payments and one with eight cost the same to settle,
| list and present; a page of invoices settles in a fixed number of queries.
| Asserted as "does not grow", so a presenter that lazy-loads per payment
| fails the day it is written.
|
*/

function pqCount(callable $work): int
{
    $count = 0;

    DB::connection('tenant')->listen(function () use (&$count): void {
        $count++;
    });

    $work();

    return $count;
}

function pqSplitInvoice(array $seed, $owner, int $payments): Invoice
{
    $invoice = test()->issuedInvoice($seed, $owner);

    for ($i = 0; $i < $payments; $i++) {
        $payment = app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 2000);
        app(RequestRefund::class)($payment, $owner, PaymentMethod::Cash, 500, 'Partly redone');
    }

    return $invoice;
}

it('settles, lists and presents an invoice\'s payments in a fixed number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $read = function (Invoice $invoice) use ($owner): void {
            $query = app(PaymentsQuery::class);
            $presenter = app(PaymentsPresenter::class);

            $fresh = $query->invoice($invoice->uuid, $owner);
            $presenter->settlement(app(InvoiceSettlement::class)->forInvoice($fresh));
            array_map(fn (Payment $payment): array => $presenter->payment($payment), $query->forInvoice($fresh));
        };

        $two = pqSplitInvoice($seed, $owner, 2);
        $eight = pqSplitInvoice($seed, $owner, 8);

        $forTwo = pqCount(fn () => $read($two));
        $forEight = pqCount(fn () => $read($eight));

        expect($forEight)->toBe($forTwo)
            ->and($forTwo)->toBeLessThanOrEqual(8);
    });
});

it('settles a page of invoices in the same queries as one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $one = [pqSplitInvoice($seed, $owner, 1)];
        $six = [$one[0]];

        for ($i = 0; $i < 5; $i++) {
            $six[] = pqSplitInvoice($seed, $owner, 2);
        }

        $forOne = pqCount(fn () => app(InvoiceSettlement::class)->forInvoices($one));
        $forSix = pqCount(fn () => app(InvoiceSettlement::class)->forInvoices($six));

        expect($forSix)->toBe($forOne)
            ->and(app(InvoiceSettlement::class)->forInvoices($six))->toHaveCount(6);
    });
});

it('renders the desk panel in the same queries for two payments as for eight', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->actingAs($owner, 'web');

        $two = pqSplitInvoice($seed, $owner, 2);
        $eight = pqSplitInvoice($seed, $owner, 8);

        // Warm the component and permission caches outside the measurement.
        Livewire::test(InvoicePayments::class, ['invoice' => $two->uuid]);

        $forTwo = pqCount(fn () => Livewire::test(InvoicePayments::class, ['invoice' => $two->uuid]));
        $forEight = pqCount(fn () => Livewire::test(InvoicePayments::class, ['invoice' => $eight->uuid]));

        expect($forEight)->toBe($forTwo);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
