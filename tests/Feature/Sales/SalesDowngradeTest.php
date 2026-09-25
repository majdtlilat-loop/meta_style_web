<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Livewire\Center\PointOfSale;
use App\Livewire\Center\Sales as SalesScreen;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\RotateInvoiceLink;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\View\Manager\FeatureOffer;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Losing POS stops new sales, not history
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §15, docs/05-ENTITLEMENTS.md §6.2.
|
| The rule Booking follows for appointments: mutations need the entitlement,
| reading what already exists does not. A finalized sale and its invoice are
| financial history. `pos` gates what happens NEXT; `printing` still gates the
| paper, separately.
|
*/

/**
 * Issues an invoice while the center owns POS, and hands back what a desk and
 * a customer would hold afterwards.
 *
 * @return array{sale: Sale, invoice: Invoice, token: string, branch: string}
 */
function sdIssue(): array
{
    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();

    // The baseline, pinned: this center owns POS when the invoice is issued.
    expect(app(Entitlements::class)->enabled('pos'))->toBeTrue();

    test()->openShift($seed['branch'], $owner);
    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

    $issued = app(FinalizeSale::class)($sale, $owner);

    return [
        'sale' => $sale->fresh() ?? $sale,
        'invoice' => $issued->invoice,
        'token' => (string) $issued->shareToken,
        'branch' => $seed['branch']->uuid,
    ];
}

it('keeps issued sales and invoices readable to staff after POS is withdrawn, and refuses anything new', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $issued = $this->asCenter($center['tenant'], function (): array {
        $issued = sdIssue();
        $issued['row'] = Invoice::query()->whereKey($issued['invoice']->id)->firstOrFail()->getAttributes();

        $this->revokeEntitlement('pos');

        expect(app(Entitlements::class)->enabled('pos'))->toBeFalse();

        return $issued;
    });

    $invoice = $issued['invoice'];

    // Staff read the history — through the query layer the screens use…
    $this->asCenter($center['tenant'], function () use ($issued, $invoice): void {
        $owner = $this->ownerWithCatalogAccess();

        [$read, $sale] = app(SalesQuery::class)->invoice($invoice->uuid, $owner);

        expect($read->number)->toBe($invoice->number)
            ->and($sale->status)->toBe(SaleStatus::Finalized)
            ->and(app(SalesQuery::class)->find($issued['sale']->uuid, $owner)->uuid)->toBe($issued['sale']->uuid)
            ->and(app(SalesQuery::class)->forBranch($owner, $issued['branch']))->toHaveCount(1);
    });

    // …and through the API.
    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/invoices/{$invoice->uuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.invoice.document.number', $invoice->number);

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/sales/{$issued['sale']->uuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.sale.status', 'finalized');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/sales?branch={$issued['branch']}")
        ->assertStatus(200)
        ->assertJsonPath('data.sales.0.invoice.uuid', $invoice->uuid);

    // Nothing new: no sale, no void — over the API and in the Actions.
    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/sales', ['branch' => $issued['branch']])
        ->assertStatus(403);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$issued['sale']->uuid}/void", ['reason' => 'After the downgrade'])
        ->assertStatus(403);

    $this->asCenter($center['tenant'], function () use ($issued, $invoice): void {
        $owner = $this->ownerWithCatalogAccess();

        expect(fn () => app(CreateDraftSale::class)($issued['branch'], $owner))->toThrow(EntitlementRequired::class)
            ->and(fn () => app(CloseSale::class)->void($issued['sale'], $owner, 'After the downgrade'))->toThrow(EntitlementRequired::class);

        // The document is exactly what was published, and still cannot change.
        expect(Invoice::query()->whereKey($invoice->id)->firstOrFail()->getAttributes())->toBe($issued['row'])
            ->and($issued['sale']->fresh()?->status)->toBe(SaleStatus::Finalized)
            ->and(Sale::query()->count())->toBe(1);

        expect(fn () => $invoice->forceFill(['grand_total_minor' => 1])->save())->toThrow(LogicException::class, 'immutable');
    });
});

it('keeps the customer\'s existing invoice link working after POS is withdrawn', function (): void {
    $center = $this->registerCenter();

    $issued = $this->asCenter($center['tenant'], function (): array {
        $issued = sdIssue();

        $this->revokeEntitlement('pos');

        return $issued;
    });

    // Phase 15: the public API resolves on the center's own host, its slug in the path.
    $slug = $center['registration']->requested_slug;
    $api = 'http://'.$slug.'.localhost:8000/api/v1/invoices/'.$slug.'/';

    $this->getJson($api.$issued['token'])
        ->assertStatus(200)
        ->assertJsonPath('data.invoice.number', $issued['invoice']->number);

    // Phase 15: the customer's page lives on the center's own host.
    $this->get('http://'.$center['registration']->requested_slug.'.localhost:8000/i/'.$issued['token'])
        ->assertStatus(200)
        ->assertSee($issued['invoice']->number);

    // A leaked link can still be revoked: securing history is not a new sale.
    $fresh = $this->asCenter($center['tenant'], fn (): string => app(RotateInvoiceLink::class)($issued['invoice'], $this->ownerWithCatalogAccess()));

    $this->getJson($api.$issued['token'])->assertStatus(404);
    $this->getJson($api.$fresh)->assertStatus(200);
});

it('lets printing follow `printing` alone once POS is gone', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $invoice = $this->asCenter($center['tenant'], function (): Invoice {
        $this->grantPrinting();
        $issued = sdIssue();

        $this->revokeEntitlement('pos');

        return $issued['invoice'];
    });

    // Phase 15: the Manager — and its print pages — live on the center's own
    // host, under /manager.
    $manager = 'http://'.$center['registration']->requested_slug.'.localhost:8000/manager';

    // POS withdrawn, Printing owned: the paper still prints.
    $this->asCenter($center['tenant'], function () use ($owner, $manager, $invoice): void {
        $this->actingAs($owner)
            ->get("{$manager}/sales/invoices/{$invoice->uuid}/print/80mm")
            ->assertOk()
            ->assertSee($invoice->number);

        $this->revokeEntitlement('printing');

        // Printing withdrawn too: no paper, while the digital invoice stays.
        $this->actingAs($owner)
            ->get("{$manager}/sales/invoices/{$invoice->uuid}/print/a4")
            ->assertStatus(403);
    });

    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($center['tenant'])))
        ->getJson("/api/v1/tenant/invoices/{$invoice->uuid}")
        ->assertStatus(200);
});

it('shows the history screen read-only and closes the till when POS is withdrawn', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $issued = sdIssue();
        $owner = $this->ownerWithCatalogAccess();

        $this->revokeEntitlement('pos');
        $this->actingAs($owner, 'web');

        // The Manager's shared downgrade notice (Phase 15) replaces the page's
        // own sentence; the rule it states is the same: history stays readable.
        $feature = app(FeatureOffer::class)->for('pos')['name'] ?? 'pos';

        Livewire::test(SalesScreen::class)
            ->set('branch', $issued['branch'])
            ->call('show', $issued['sale']->uuid)
            ->assertSet('error', '')
            ->assertSee($issued['invoice']->number)
            ->assertSee(__('manager_features.ui.history_notice', ['feature' => $feature]))
            ->assertDontSee('Void sale');

        // The till is for NEW sales: the upgrade state, never a raw
        // entitlement key such as "[pos]".
        Livewire::test(PointOfSale::class)
            ->assertSee(__('manager_features.ui.eyebrow'))
            ->assertSee($feature)
            ->assertDontSee('[pos]')
            ->assertDontSee('Finalize and issue invoice');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
