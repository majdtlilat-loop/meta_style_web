<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Data\IssuedInvoice;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\SaleSource;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The life of a sale
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§3–5, 9, 18–19, 31.
|
|   draft ──finalize──▶ finalized ──void──▶ voided
|
| A draft IS the cart. Finalizing publishes an invoice and freezes the sale.
| Nothing about money moving lives here: that is Payment, in Phase 10.
|
*/

function slSeed(): array
{
    return test()->seedBookableCenter();
}

it('opens a direct POS sale with no visit and no appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);

        expect($sale->status)->toBe(SaleStatus::Draft)
            ->and($sale->source)->toBe(SaleSource::Pos)
            ->and($sale->service_journey_id)->toBeNull()
            ->and($sale->customer_id)->toBeNull()
            ->and($sale->currency)->toBe('IQD')
            ->and($sale->grand_total_minor)->toBe(0)
            // No fake visit was invented to hold it.
            ->and(ServiceJourney::query()->count())->toBe(0);
    });
});

it('returns the same draft for a repeated request token', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();
        $token = (string) Str::uuid();

        $first = app(CreateDraftSale::class)($seed['branch']->uuid, $owner, null, $token);
        $again = app(CreateDraftSale::class)($seed['branch']->uuid, $owner, null, $token);

        expect($again->uuid)->toBe($first->uuid)
            ->and(Sale::query()->count())->toBe(1);
    });
});

it('prices a cart on the server and ignores anything else', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        $medium = $seed['service']->variations()->where('price_minor', 25000)->firstOrFail();

        app(AddSaleLine::class)($sale, $owner, [
            'kind' => 'service',
            'service' => $seed['service']->uuid,
            'variation' => $medium->uuid,
            'addons' => [$seed['addon']->uuid],
            'quantity' => 2,
            // A client that tries to name its own price is simply not heard:
            // only a custom line reads a price at all.
            'unit_price_minor' => 1,
        ]);

        $sale->refresh();
        $line = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        // (25 000 + 5 000) × 2
        expect($line->unit_price_minor)->toBe(25000)
            ->and($line->original_unit_price_minor)->toBe(25000)
            ->and($line->addons_unit_total_minor)->toBe(5000)
            ->and($line->line_subtotal_minor)->toBe(60000)
            ->and($sale->subtotal_minor)->toBe(60000)
            ->and($sale->grand_total_minor)->toBe(60000);
    });
});

it('changes quantities and removes lines, re-pricing the whole cart each time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();
        $product = $this->seedProduct('Shampoo', 12000);

        $sale = $this->draftSale($seed['branch'], $owner);

        $haircut = app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        $shampoo = app(AddSaleLine::class)($sale, $owner, ['kind' => 'product', 'product' => $product->uuid]);

        expect($sale->fresh()?->grand_total_minor)->toBe(32000);

        app(ChangeSaleLine::class)->update($sale, $shampoo->uuid, $owner, ['quantity' => 3]);
        expect($sale->fresh()?->grand_total_minor)->toBe(56000);

        app(ChangeSaleLine::class)->remove($sale, $haircut->uuid, $owner);
        expect($sale->fresh()?->grand_total_minor)->toBe(36000)
            ->and(SaleItem::query()->where('sale_id', $sale->id)->count())->toBe(1);
    });
});

it('finalizes atomically into one immutable invoice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountFixed, 2000, 'Regular customer');

        $invoice = app(FinalizeSale::class)($sale, $owner)->invoice;

        $sale->refresh();

        expect($sale->status)->toBe(SaleStatus::Finalized)
            ->and($sale->finalized_at)->not->toBeNull()
            ->and($sale->cashier_shift_id)->not->toBeNull()
            ->and($invoice->sale_id)->toBe($sale->id)
            ->and($invoice->number)->toBe('INV-'.now()->year.'-000001')
            ->and($invoice->subtotal_minor)->toBe(20000)
            ->and($invoice->discount_total_minor)->toBe(2000)
            ->and($invoice->grand_total_minor)->toBe(18000)
            ->and($invoice->items()->count())->toBe(1)
            ->and($invoice->activeLink()->exists())->toBeTrue();
    });
});

it('returns the same invoice when finalize is pressed twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        // Both desks hold the model as their screen rendered it: a draft.
        $first = app(FinalizeSale::class)($sale, $owner);
        $second = app(FinalizeSale::class)($sale, $owner);

        expect($second->invoice->uuid)->toBe($first->invoice->uuid)
            // Only the call that published it holds the link's secret; the
            // repeat cannot recover a value that was never stored.
            ->and($first->replayed)->toBeFalse()
            ->and($first->shareToken)->toMatch('/^[a-f0-9]{64}$/')
            ->and($second->replayed)->toBeTrue()
            ->and($second->shareToken)->toBeNull()
            ->and(Invoice::query()->count())->toBe(1)
            ->and(TenantAuditLog::query()->where('action', 'invoice.issued')->count())->toBe(1);
    });
});

it('refuses to finalize an empty sale or one without an open shift', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        // A sale nobody's till session is accountable for is refused.
        expect(fn (): IssuedInvoice => app(FinalizeSale::class)($sale, $owner))
            ->toThrow(SaleFailed::class, 'Open your cashier shift');

        $this->openShift($seed['branch'], $owner);

        $empty = $this->draftSale($seed['branch'], $owner);

        expect(fn (): IssuedInvoice => app(FinalizeSale::class)($empty, $owner))
            ->toThrow(SaleFailed::class, 'empty sale');

        // And a failed finalization consumed nothing.
        expect(Invoice::query()->count())->toBe(0)
            ->and(DB::connection('tenant')->table('invoice_sequences')->value('last_number'))->toBeNull();
    });
});

it('freezes a finalized sale against every kind of edit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);
        $customer = $this->seedCustomer();

        $sale = $this->draftSale($seed['branch'], $owner);
        $line = app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(FinalizeSale::class)($sale, $owner);

        $attempts = [
            'add line' => fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]),
            'quantity' => fn () => app(ChangeSaleLine::class)->update($sale, $line->uuid, $owner, ['quantity' => 2]),
            'remove' => fn () => app(ChangeSaleLine::class)->remove($sale, $line->uuid, $owner),
            'override' => fn () => app(ChangeSaleLine::class)->overridePrice($sale, $line->uuid, $owner, 1, 'Just because'),
            'discount' => fn () => app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountFixed, 100, 'Too late'),
            'customer' => fn () => app(AdjustSale::class)->customer($sale, $owner, $customer->uuid),
            'discard' => fn () => app(CloseSale::class)->discard($sale, $owner),
        ];

        foreach ($attempts as $name => $attempt) {
            expect($attempt)->toThrow(SaleFailed::class, null, $name);
        }

        $sale->refresh();

        expect($sale->status)->toBe(SaleStatus::Finalized)
            ->and($sale->customer_id)->toBeNull()
            ->and($sale->grand_total_minor)->toBe(20000)
            ->and(SaleItem::query()->where('sale_id', $sale->id)->count())->toBe(1);
    });
});

it('discards a draft entirely, keeping only the audit fact', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        app(CloseSale::class)->discard($sale, $owner);

        $entry = TenantAuditLog::query()->where('action', 'sale.discarded')->firstOrFail();

        expect(Sale::query()->count())->toBe(0)
            ->and(SaleItem::query()->count())->toBe(0)
            ->and($entry->target_id)->toBe($sale->uuid)
            ->and($entry->before['grand_total_minor'])->toBe(20000);
    });
});

it('refuses a service that is not offered at the sale branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = slSeed();
        $owner = $this->ownerWithCatalogAccess();

        $other = $this->seedBranch('Mansour');
        $seed['service']->forceFill(['available_at_all_branches' => false])->save();
        $seed['service']->branches()->sync([$seed['branch']->id]);

        $sale = $this->draftSale($other, $owner);

        expect(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]))
            ->toThrow(SaleFailed::class, 'not offered at this branch');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
