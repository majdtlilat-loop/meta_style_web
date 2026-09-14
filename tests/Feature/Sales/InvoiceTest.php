<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\InvoiceRenderer;
use App\Modules\Sales\Application\PublicInvoice;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceItem;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| The invoice
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§14–16, 19–20, 37, ADR-054.
|
| Published once, never edited, never deleted. It carries its own copies of
| everything it shows, so nothing that happens to the catalog, the branch or the
| sale afterwards can change a document a customer already holds.
|
*/

function ivSeed(): array
{
    return test()->seedBookableCenter();
}

/**
 * @return array{0: Sale, 1: Invoice}
 */
function ivIssue(array $seed, $owner, array $lines = [['kind' => 'service']]): array
{
    test()->openShift($seed['branch'], $owner);

    $sale = test()->draftSale($seed['branch'], $owner);

    foreach ($lines as $line) {
        app(AddSaleLine::class)($sale, $owner, $line + ['service' => $seed['service']->uuid]);
    }

    $issued = app(FinalizeSale::class)($sale, $owner);

    // The customer link's secret exists only here: the row keeps its digest.
    return [$sale, $issued->invoice, (string) $issued->shareToken];
}

it('never changes a finalized sale, its invoice or its documents when prices and names change', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();
        $product = $this->seedProduct('Shampoo', 12000);

        [$sale, $invoice, $token] = ivIssue($seed, $owner, [
            ['kind' => 'service'],
            ['kind' => 'product', 'product' => $product->uuid],
        ]);

        $renderer = app(InvoiceRenderer::class);

        $documentBefore = $renderer->document($invoice->fresh() ?? $invoice, 'en', SaleStatus::Finalized);
        $publicBefore = app(PublicInvoice::class)->forToken((string) $token, 'en');
        $saleBefore = $sale->fresh()?->only(['subtotal_minor', 'grand_total_minor']);

        // Everything the invoice was built from moves on.
        $seed['service']->forceFill(['price_minor' => 55000, 'name' => TranslatedText::fromArray(['en' => 'Premium Cut'])])->save();
        $product->forceFill(['price_minor' => 99000, 'name' => TranslatedText::fromArray(['en' => 'Luxury Shampoo'])])->save();
        $seed['branch']->forceFill(['name' => TranslatedText::fromArray(['en' => 'Renamed Branch']), 'phone' => '+9647700000000'])->save();

        expect($renderer->document($invoice->fresh() ?? $invoice, 'en', SaleStatus::Finalized))->toBe($documentBefore)
            ->and(app(PublicInvoice::class)->forToken((string) $token, 'en'))->toBe($publicBefore)
            ->and($sale->fresh()?->only(['subtotal_minor', 'grand_total_minor']))->toBe($saleBefore)
            ->and($documentBefore['grand_total']['amount'])->toBe(32000)
            ->and(array_column($documentBefore['lines'], 'name'))->toBe(['Haircut', 'Shampoo']);
    });
});

it('refuses to update or delete a published invoice or its lines', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();

        [, $invoice] = ivIssue($seed, $owner);

        expect(fn () => $invoice->forceFill(['grand_total_minor' => 1])->save())->toThrow(LogicException::class, 'immutable');
        expect(fn () => $invoice->delete())->toThrow(LogicException::class, 'never deleted');

        $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->firstOrFail();

        expect(fn () => $item->forceFill(['quantity' => 9])->save())->toThrow(LogicException::class, 'immutable');

        expect(Invoice::query()->whereKey($invoice->id)->value('grand_total_minor'))->toBe(20000)
            ->and(InvoiceItem::query()->whereKey($item->id)->value('quantity'))->toBe(1);
    });
});

it('snapshots the customer name and adjustments, and never a phone', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.test');

        $this->openShift($seed['branch'], $owner);
        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);
        app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountPercent, 1000, 'Staff friend');

        $invoice = app(FinalizeSale::class)($sale, $owner)->invoice;

        // A later rename of the customer does not reach the document.
        $customer->forceFill(['name' => 'Sara A. Renamed'])->save();

        $document = app(InvoiceRenderer::class)->document($invoice->fresh() ?? $invoice, 'en', SaleStatus::Finalized);
        $flat = json_encode($document, JSON_THROW_ON_ERROR);

        expect($document['customer_name'])->toBe('Sara Ahmed')
            ->and($document['adjustments'])->toHaveCount(1)
            ->and($document['adjustments'][0]['percent'])->toBe('10')
            ->and($document['adjustments'][0]['amount']['amount'])->toBe(-2000)
            ->and($document['grand_total']['amount'])->toBe(18000)
            ->and($flat)->not->toContain('+964750')
            ->and($flat)->not->toContain('sara@example.test')
            // The internal REASON for a discount is staff business.
            ->and($flat)->not->toContain('Staff friend');
    });
});

it('prints the issue time on the branch clock and numbers by the branch-local year', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();

        $this->openShift($seed['branch'], $owner);
        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        // 21:30 UTC on 31 December is 00:30 on 1 January in Baghdad.
        $invoice = app(FinalizeSale::class)($sale, $owner, CarbonImmutable::parse('2026-12-31 21:30:00', 'UTC'))->invoice;

        $document = app(InvoiceRenderer::class)->document($invoice, 'en', SaleStatus::Finalized);

        expect($invoice->sequence_year)->toBe(2027)
            ->and($invoice->number)->toBe('INV-2027-000001')
            ->and($invoice->issued_timezone)->toBe('Asia/Baghdad')
            ->and($document['issued_date'])->toBe('2027-01-01')
            ->and($document['issued_time'])->toBe('00:30');
    });
});

it('voids a sale without touching its invoice, which then reads VOID', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();

        [$sale, $invoice, $token] = ivIssue($seed, $owner);

        $rowBefore = Invoice::query()->whereKey($invoice->id)->firstOrFail()->getAttributes();

        expect(fn () => app(CloseSale::class)->void($sale, $owner, ''))->toThrow(SaleFailed::class, 'needs a reason');

        $voided = app(CloseSale::class)->void($sale, $owner, 'Customer disputed the charge');

        expect($voided->status)->toBe(SaleStatus::Voided)
            ->and($voided->void_reason)->toBe('Customer disputed the charge')
            ->and($voided->voided_by_id)->toBe($owner->uuid)
            // The invoice row is byte-for-byte what was published.
            ->and(Invoice::query()->whereKey($invoice->id)->firstOrFail()->getAttributes())->toBe($rowBefore);

        $public = app(PublicInvoice::class)->forToken($token, 'en');

        expect($public['voided'])->toBeTrue()
            ->and($public['voided_date'])->not->toBeNull()
            // The reason is not the customer's to read.
            ->and(json_encode($public, JSON_THROW_ON_ERROR))->not->toContain('disputed');

        // A second void is the same outcome, not an error or a second record.
        expect(app(CloseSale::class)->void($sale, $owner, 'Again')->void_reason)->toBe('Customer disputed the charge');
    });
});

it('voids only finalized sales, and only with the void permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();

        $draft = $this->draftSale($seed['branch'], $owner);

        expect(fn () => app(CloseSale::class)->void($draft, $owner, 'Not issued yet'))
            ->toThrow(SaleFailed::class, 'Discard a draft');

        [$sale] = ivIssue($seed, $owner);

        $cashier = $this->staffWith([Permission::SaleView, Permission::SaleCreate, Permission::SaleFinalize], 'till@alpha.test');

        expect(fn () => app(CloseSale::class)->void($sale, $cashier, 'I changed my mind'))
            ->toThrow(AuthorizationException::class);

        expect($sale->fresh()?->status)->toBe(SaleStatus::Finalized);
    });
});

it('refuses to finalize a voided sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ivSeed();
        $owner = $this->ownerWithCatalogAccess();

        [$sale] = ivIssue($seed, $owner);
        app(CloseSale::class)->void($sale, $owner, 'Wrong customer');

        expect(fn () => app(FinalizeSale::class)($sale, $owner))->toThrow(SaleFailed::class, 'voided');
        expect(Invoice::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
