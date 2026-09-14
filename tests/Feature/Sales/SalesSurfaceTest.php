<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Center\PointOfSale;
use App\Livewire\Center\Sales as SalesScreen;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Sales surfaces
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§42–46.
|
| The API and the till call the same Actions. These drive both ends and assert
| the outcome, not the implementation — and that there is no payment endpoint
| anywhere to drive.
|
*/

function ssSeed(): array
{
    return test()->seedBookableCenter();
}

it('drives a sale from empty cart to voided invoice through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], function (): array {
        $seed = ssSeed();
        $seed['product'] = $this->seedProduct('Shampoo', 12000, '6291041500213');

        return $seed;
    });

    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/cashier-shifts', ['branch' => $seed['branch']->uuid])
        ->assertStatus(201)
        ->assertJsonPath('data.shift.status', 'open');

    $created = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/sales', ['branch' => $seed['branch']->uuid, 'idempotency_token' => 'surface-sale-1'])
        ->assertStatus(201);

    $uuid = $created->json('data.sale.uuid');

    expect($created->json('data.sale.status'))->toBe('draft');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/items", ['kind' => 'service', 'service' => $seed['service']->uuid, 'addons' => [$seed['addon']->uuid]])
        ->assertStatus(201)
        ->assertJsonPath('data.sale.grand_total.amount', 25000);

    $withProduct = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/items", ['kind' => 'product', 'product' => $seed['product']->uuid, 'quantity' => 2])
        ->assertStatus(201)
        ->assertJsonPath('data.sale.grand_total.amount', 49000);

    $productLine = $withProduct->json('data.sale.lines.1.uuid');

    $this->withHeaders($headers)
        ->patchJson("/api/v1/tenant/sales/{$uuid}/items/{$productLine}", ['quantity' => 1])
        ->assertStatus(200)
        ->assertJsonPath('data.sale.grand_total.amount', 37000);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/adjustments", ['type' => 'discount_percent', 'value' => 1000, 'reason' => 'Opening week'])
        ->assertStatus(201)
        ->assertJsonPath('data.sale.discount_total.amount', 3700)
        ->assertJsonPath('data.sale.grand_total.amount', 33300);

    $finalized = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/finalize")
        ->assertStatus(200);

    $invoiceUuid = $finalized->json('data.invoice.uuid');

    expect($finalized->json('data.invoice.document.grand_total.amount'))->toBe(33300)
        ->and($finalized->json('data.invoice.summary.share_url'))->toContain('/i/');

    // Pressed twice: the same invoice — and no URL, because the link's secret
    // was stored only as a digest and cannot be handed out a second time.
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/finalize")
        ->assertStatus(200)
        ->assertJsonPath('data.invoice.uuid', $invoiceUuid)
        ->assertJsonPath('data.invoice.summary.share_url', null)
        ->assertJsonPath('data.invoice.summary.share_link_active', true);

    // Editing a finalized sale is refused with the sales error code.
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/items", ['kind' => 'service', 'service' => $seed['service']->uuid])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SALES.INVALID_TRANSITION');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/invoices/{$invoiceUuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.invoice.document.voided', false)
        ->assertJsonPath('data.invoice.summary.share_url', null);

    // A desk that needs the link again issues a new one; the first stops working.
    $firstLink = (string) $finalized->json('data.invoice.summary.share_url');
    $reissued = (string) $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/invoices/{$invoiceUuid}/share-link")
        ->assertStatus(200)
        ->json('data.invoice.summary.share_url');

    expect($reissued)->toContain('/i/')->not->toBe($firstLink);

    $this->get(parse_url($firstLink, PHP_URL_PATH))->assertStatus(404);
    $this->get(parse_url($reissued, PHP_URL_PATH))->assertStatus(200);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/sales/{$uuid}/void", ['reason' => 'Rang up the wrong customer'])
        ->assertStatus(200)
        ->assertJsonPath('data.sale.status', 'voided');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/sales?branch={$seed['branch']->uuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.sales.0.uuid', $uuid)
        ->assertJsonPath('data.sales.0.invoice.uuid', $invoiceUuid);

    $this->asCenter($center['tenant'], function (): void {
        expect(Sale::query()->firstOrFail()->status)->toBe(SaleStatus::Voided)
            ->and(Invoice::query()->count())->toBe(1);
    });
});

it('checks a visit out through the API, once', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $journey = $this->asCenter($center['tenant'], function () {
        return $this->walkInVisit(ssSeed(), $this->ownerWithCatalogAccess(), ['completed']);
    });

    $first = $this->withHeaders($headers)->postJson("/api/v1/tenant/journeys/{$journey->uuid}/checkout")->assertStatus(200);
    $second = $this->withHeaders($headers)->postJson("/api/v1/tenant/journeys/{$journey->uuid}/checkout")->assertStatus(200);

    expect($second->json('data.sale.uuid'))->toBe($first->json('data.sale.uuid'))
        ->and($first->json('data.sale.source'))->toBe('walk_in_checkout')
        ->and($first->json('data.sale.lines.0.from_visit'))->toBeTrue();
});

it('keeps every sales route behind authentication, and offers no payment endpoint at all', function (): void {
    $sales = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'sales')
            || str_contains($route->uri(), 'cashier-shifts')
            || str_contains($route->uri(), 'tenant/invoices')
            || str_contains($route->uri(), 'products')
            || str_contains($route->uri(), '/pos'));

    expect($sales)->not->toBeEmpty();

    foreach ($sales as $route) {
        expect($route->gatherMiddleware())->toContain(
            str_starts_with($route->uri(), 'api/') ? 'auth:sanctum' : 'auth:web',
        );
    }

    // Settlement is Phase 10. Nothing in the route table may pretend otherwise.
    $payments = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => preg_match('/pay|refund|settle|gateway|webhook|zaincash|fib|fastpay/i', $route->uri()) === 1);

    expect($payments->map(fn ($route) => $route->uri())->values()->all())->toBe([]);
});

it('refuses sales work to a user without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ssSeed();
        $viewer = $this->staffWith([Permission::SaleView], 'viewer@alpha.test');

        expect(fn () => $this->draftSale($seed['branch'], $viewer))
            ->toThrow(AuthorizationException::class);
    });
});

it('renders the till and the sales list', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], fn (): array => ssSeed());

    $session = [
        StanclTenantResolver::SESSION_KEY => $this->publicKeyOf($center['tenant']),
        Auth::guard('web')->getName() => $owner->getAuthIdentifier(),
    ];

    $this->withSession($session)->get('/center/pos')->assertOk()->assertSee('Till');
    $this->withSession($session)->get('/center/sales')->assertOk()->assertSee('Sales');
});

it('builds, finalizes and shows a sale from the till screen', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ssSeed();
        $owner = $this->ownerWithCatalogAccess();

        $this->actingAs($owner, 'web');

        $component = Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('openShift')
            ->call('newSale')
            ->assertSet('error', '');

        $saleUuid = $component->get('sale');

        $component
            ->call('pick', $seed['service']->uuid)
            ->call('addService')
            ->set('adjustmentType', 'discount_percent')
            ->set('adjustmentValue', '12.5')
            ->set('adjustmentReason', 'First visit')
            ->call('addAdjustment')
            ->assertSet('error', '')
            ->call('finalize')
            ->assertSet('error', '')
            ->assertSee('INV-');

        $sale = Sale::query()->where('uuid', $saleUuid)->firstOrFail();

        // 12.5% of 20 000 = 2 500 — computed by the server, typed as a string.
        expect($sale->status)->toBe(SaleStatus::Finalized)
            ->and($sale->discount_total_minor)->toBe(2500)
            ->and($sale->grand_total_minor)->toBe(17500);
    });
});

it('opens checkout for a visit from its link, and lands on the same cart twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ssSeed();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->walkInVisit($seed, $owner, ['completed', 'skipped']);

        $this->actingAs($owner, 'web');

        $first = Livewire::withQueryParams(['journey' => $journey->uuid])->test(PointOfSale::class)
            ->assertSet('error', '')
            ->assertSee('Booked')
            ->assertSee('Performed')
            ->assertSee('Charged');

        $second = Livewire::withQueryParams(['journey' => $journey->uuid])->test(PointOfSale::class);

        expect($second->get('sale'))->toBe($first->get('sale'))
            ->and(Sale::query()->count())->toBe(1);
    });
});

it('voids a sale from the sales list with a reason', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ssSeed();
        $owner = $this->ownerWithCatalogAccess();

        $this->openShift($seed['branch'], $owner);
        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(FinalizeSale::class)($sale, $owner);

        $this->actingAs($owner, 'web');

        Livewire::test(SalesScreen::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('show', $sale->uuid)
            ->set('voidReason', 'Wrong service rung up')
            ->call('void')
            ->assertSet('error', '');

        expect($sale->fresh()?->status)->toBe(SaleStatus::Voided);
    });
});

it('lets a manager add a product and set a branch invoice prefix from the sales screen', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = ssSeed();
        $owner = $this->ownerWithCatalogAccess();

        $this->actingAs($owner, 'web');

        Livewire::test(SalesScreen::class)
            ->set('branch', $seed['branch']->uuid)
            ->set('productName', 'Beard Oil')
            ->set('productPrice', '15000')
            ->set('productBarcode', '6291041500213')
            ->call('addProduct')
            ->assertSet('error', '')
            ->set('invoicePrefix', 'bg')
            ->call('setInvoicePrefix')
            ->assertSet('error', '')
            ->assertSet('invoicePrefix', 'BG');

        expect(Product::query()->where('barcode', '6291041500213')->value('price_minor'))->toBe(15000)
            ->and($seed['branch']->fresh()?->invoice_prefix)->toBe('BG');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
