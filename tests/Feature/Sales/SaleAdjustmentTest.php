<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\PriceSource;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Discounts, surcharges and price overrides
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§10–13.
|
| MANUAL adjustments only — no promotions, loyalty or memberships. Every one of
| them needs `sale.adjust` and a reason, and none of them is silent: an overridden
| price keeps the original next to it, and every change is audited.
|
*/

function saSeed(): array
{
    return test()->seedBookableCenter();
}

it('applies a percentage discount with one rounding rule and allocates it exactly', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();
        $product = $this->seedProduct('Comb', 3333);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'product', 'product' => $product->uuid, 'quantity' => 3]);

        // 12.5% of 29 999 = 3 749.875 → 3 750
        app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountPercent, 1250, 'Loyal regular');

        $sale->refresh();
        $allocated = (int) SaleItem::query()->where('sale_id', $sale->id)->sum('discount_allocated_minor');

        expect($sale->subtotal_minor)->toBe(29999)
            ->and($sale->discount_total_minor)->toBe(3750)
            ->and($sale->grand_total_minor)->toBe(26249)
            ->and($allocated)->toBe(3750)
            ->and(SaleAdjustment::query()->firstOrFail()->amount_minor)->toBe(3750);
    });
});

it('adds a surcharge and removes an adjustment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        $fee = app(AdjustSale::class)->add($sale, $owner, AdjustmentType::SurchargeFixed, 2500, 'Home visit fee');
        expect($sale->fresh()?->grand_total_minor)->toBe(22500);

        app(AdjustSale::class)->remove($sale, $fee->uuid, $owner);
        expect($sale->fresh()?->grand_total_minor)->toBe(20000)
            ->and(SaleAdjustment::query()->count())->toBe(0);
    });
});

it('refuses a discount that would make the sale negative, and changes nothing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        expect(fn () => app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountFixed, 20001, 'Too generous'))
            ->toThrow(SaleFailed::class, 'larger than the sale');

        // The adjustment row was rolled back with the recalculation that refused it.
        expect(SaleAdjustment::query()->count())->toBe(0)
            ->and($sale->fresh()?->grand_total_minor)->toBe(20000);
    });
});

it('refuses to remove a line when that would leave a discount bigger than the sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();
        $product = $this->seedProduct('Wax', 10000);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        $wax = app(AddSaleLine::class)($sale, $owner, ['kind' => 'product', 'product' => $product->uuid]);
        app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountFixed, 25000, 'Bundle price');

        // 20 000 left against a 25 000 discount: the whole change is refused.
        expect(fn () => app(ChangeSaleLine::class)->remove($sale, $wax->uuid, $owner))
            ->toThrow(SaleFailed::class, 'larger than the sale');

        expect(SaleItem::query()->count())->toBe(2)
            ->and($sale->fresh()?->grand_total_minor)->toBe(5000);
    });
});

it('requires a reason and the adjust permission for every adjustment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        expect(fn () => app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountFixed, 1000, ' '))
            ->toThrow(SaleFailed::class, 'needs a reason');

        // A cashier builds and finalizes carts; discounts are a manager's call.
        $cashier = $this->staffWith([Permission::SaleView, Permission::SaleCreate, Permission::SaleFinalize], 'till@alpha.test');

        expect(fn () => app(AdjustSale::class)->add($sale, $cashier, AdjustmentType::DiscountFixed, 1000, 'Please'))
            ->toThrow(AuthorizationException::class);

        expect(fn () => app(ChangeSaleLine::class)->overridePrice(
            $sale,
            SaleItem::query()->firstOrFail()->uuid,
            $cashier,
            1,
            'Please',
        ))->toThrow(AuthorizationException::class);

        expect(fn () => app(AddSaleLine::class)($sale, $cashier, [
            'kind' => 'custom', 'name' => 'Anything', 'unit_price_minor' => 1, 'reason' => 'Please',
        ]))->toThrow(AuthorizationException::class);

        expect(SaleAdjustment::query()->count())->toBe(0);
    });
});

it('overrides a price visibly: the original stays, the actor and reason are kept and audited', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        $line = app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        expect(fn () => app(ChangeSaleLine::class)->overridePrice($sale, $line->uuid, $owner, 15000, null))
            ->toThrow(SaleFailed::class, 'needs a reason');

        $overridden = app(ChangeSaleLine::class)->overridePrice($sale, $line->uuid, $owner, 15000, 'Trainee stylist');

        expect($overridden->unit_price_minor)->toBe(15000)
            ->and($overridden->original_unit_price_minor)->toBe(20000)
            ->and($overridden->price_source)->toBe(PriceSource::Catalog)
            ->and($overridden->price_override_reason)->toBe('Trainee stylist')
            ->and($overridden->price_overridden_by_id)->toBe($owner->uuid)
            ->and($sale->fresh()?->grand_total_minor)->toBe(15000);

        $entry = TenantAuditLog::query()->where('action', 'sale.price_overridden')->firstOrFail();

        expect($entry->before['unit_price_minor'])->toBe(20000)
            ->and($entry->after['unit_price_minor'])->toBe(15000)
            ->and($entry->after['original_unit_price_minor'])->toBe(20000)
            ->and($entry->reason)->toBe('Trainee stylist');

        $restored = app(ChangeSaleLine::class)->overridePrice($sale, $line->uuid, $owner, null, null);

        expect($restored->unit_price_minor)->toBe(20000)
            ->and($restored->price_override_reason)->toBeNull()
            ->and($sale->fresh()?->grand_total_minor)->toBe(20000);
    });
});

it('adds a custom charge only with the adjust permission and a reason', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);

        expect(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'custom', 'name' => 'Beard oil sample', 'unit_price_minor' => 3000]))
            ->toThrow(SaleFailed::class, 'needs a reason');

        $line = app(AddSaleLine::class)($sale, $owner, [
            'kind' => 'custom', 'name' => 'Beard oil sample', 'unit_price_minor' => 3000, 'reason' => 'Not in the catalog yet',
        ]);

        expect($line->price_source)->toBe(PriceSource::Manual)
            ->and($line->name->get('ar'))->toBe('Beard oil sample')
            ->and($sale->fresh()?->grand_total_minor)->toBe(3000)
            ->and(TenantAuditLog::query()->where('action', 'sale.line_added')->firstOrFail()->reason)->toBe('Not in the catalog yet');
    });
});

it('caps the number of adjustments on one sale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = saSeed();
        $owner = $this->ownerWithCatalogAccess();

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        for ($i = 0; $i < 10; $i++) {
            app(AdjustSale::class)->add($sale, $owner, AdjustmentType::SurchargeFixed, 1, 'Fee '.$i);
        }

        expect(fn () => app(AdjustSale::class)->add($sale, $owner, AdjustmentType::SurchargeFixed, 1, 'One too many'))
            ->toThrow(SaleFailed::class, 'at most 10');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
