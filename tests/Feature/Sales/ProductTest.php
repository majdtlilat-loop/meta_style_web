<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Modules\Catalog\Application\Actions\SaveProduct;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\SaleItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§6, 8, 37.
|
| The smallest thing that can be sold over a counter: a name, a code, a price.
| Not inventory — and the schema test below keeps it that way.
|
*/

it('creates, edits and archives a product, with the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();

        $product = app(SaveProduct::class)([
            'name' => ['en' => 'Argan Oil', 'ar' => 'زيت الأركان'],
            'sku' => 'ARG-100',
            'barcode' => '6291041500213',
            'price_minor' => 15000,
        ], $owner);

        expect($product->isSellable())->toBeTrue()
            ->and($product->name->get('ar'))->toBe('زيت الأركان');

        app(SaveProduct::class)(['name' => ['en' => 'Argan Oil'], 'price_minor' => 17000, 'sku' => 'ARG-100'], $owner, $product);
        expect($product->fresh()?->price_minor)->toBe(17000);

        app(SaveProduct::class)->archive($product, $owner);
        expect($product->fresh()?->isSellable())->toBeFalse();

        $cashier = $this->staffWith([Permission::SaleCreate], 'till@alpha.test');

        expect(fn () => app(SaveProduct::class)(['name' => ['en' => 'Comb'], 'price_minor' => 1000], $cashier))
            ->toThrow(AuthorizationException::class);
    });
});

it('keeps SKUs and barcodes unique and scanner-safe', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();

        app(SaveProduct::class)(['name' => ['en' => 'One'], 'price_minor' => 1, 'barcode' => '123456'], $owner);

        expect(fn () => app(SaveProduct::class)(['name' => ['en' => 'Two'], 'price_minor' => 1, 'barcode' => '123456'], $owner))
            ->toThrow(ValidationException::class);

        expect(fn () => app(SaveProduct::class)(['name' => ['en' => 'Three'], 'price_minor' => 1, 'sku' => 'has space'], $owner))
            ->toThrow(ValidationException::class);

        expect(fn () => app(SaveProduct::class)(['name' => ['en' => 'Four'], 'price_minor' => -1], $owner))
            ->toThrow(ValidationException::class);
    });
});

it('refuses to sell an archived or inactive product', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $product = $this->seedProduct('Old stock', 5000);
        app(SaveProduct::class)->archive($product, $owner);

        $sale = $this->draftSale($seed['branch'], $owner);

        expect(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'product', 'product' => $product->uuid]))
            ->toThrow(SaleFailed::class, 'not available');
    });
});

it('never lets a later product price change reach a sale that already charged it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $product = $this->seedProduct('Shampoo', 12000);

        $draft = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($draft, $owner, ['kind' => 'product', 'product' => $product->uuid]);

        // The price changes while the cart is still open…
        app(SaveProduct::class)(['name' => ['en' => 'Shampoo'], 'price_minor' => 30000], $owner, $product);

        // …and the line keeps the price the cashier added it at, through finalize.
        $invoice = app(FinalizeSale::class)($draft, $owner)->invoice;

        expect(SaleItem::query()->where('sale_id', $draft->id)->value('unit_price_minor'))->toBe(12000)
            ->and($invoice->grand_total_minor)->toBe(12000);
    });
});

it('has no inventory columns at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        foreach (['stock', 'stock_quantity', 'quantity_on_hand', 'cost_price', 'cost_minor', 'supplier_id', 'batch', 'expires_at', 'warehouse_id'] as $column) {
            expect(Schema::connection('tenant')->hasColumn('products', $column))->toBeFalse();
        }

        expect(Schema::connection('tenant')->hasTable('stock_movements'))->toBeFalse()
            ->and(Schema::connection('tenant')->hasTable('purchase_orders'))->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
