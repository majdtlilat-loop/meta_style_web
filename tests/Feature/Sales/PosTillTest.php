<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TranslatedText;
use App\Livewire\Center\PointOfSale;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\SaleBenefits;
use App\Modules\Sales\Domain\Data\BenefitGrant;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\View\Manager\FeatureOffer;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager till
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§43–44. The till is presentation over the Sales Actions:
| these pin what it SHOWS — a customer's benefit reads as a discount, a scan
| adds the product, quantities step through the Action — and who may find a
| customer by phone.
|
*/

it('shows a benefit as a discount, adds a scanned product and steps its quantity through the Action', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->seedProduct('Beard Oil', 15000, '6291041500213');
        $this->actingAs($owner, 'web');

        $till = Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('openShift')
            ->call('newSale')
            ->assertSet('error', '')
            // A scanner types the whole barcode and presses Enter.
            ->set('search', '6291041500213')
            ->call('scan')
            ->assertSet('error', '')
            ->assertSet('search', '');

        $sale = Sale::query()->where('uuid', $till->get('sale'))->firstOrFail();
        $line = $sale->items()->firstOrFail();

        expect($sale->grand_total_minor)->toBe(15000);

        $cart = $till->viewData('cart');
        expect($cart['lines'][0]['next_quantity'])->toBe(2)
            ->and($cart['lines'][0]['can_decrease'])->toBeFalse();

        $till->call('quantity', $line->uuid, $cart['lines'][0]['next_quantity'])->assertSet('error', '');

        expect($sale->fresh()?->grand_total_minor)->toBe(30000);

        // A benefit a module granted through the Sales seam: a discount, not a
        // surcharge (it used to read "Surcharge" at the till).
        app(SaleBenefits::class)->apply($sale->fresh() ?? $sale, $owner, null, static fn (): BenefitGrant => new BenefitGrant('test_benefit', (string) Str::uuid(), 5000, 'Points redeemed'));

        $adjustment = $till->call('saleChanged')->viewData('cart')['adjustments'][0];

        expect($adjustment['is_discount'])->toBeTrue()
            ->and($adjustment['label'])->toBe('Customer benefit')
            ->and($adjustment['sign'])->toBe('−')
            ->and($sale->fresh()?->grand_total_minor)->toBe(25000);
    });
});

it('records who performed a service rung up at the till — only someone eligible at the branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $product = $this->seedProduct('Beard Oil', 15000, '6291041500213');

        // Active and at the branch, but never allowed to perform the service.
        $outsider = Employee::query()->create(['name' => TranslatedText::fromArray(['en' => 'Karim']), 'status' => EmployeeStatus::Active]);
        $outsider->branches()->attach($seed['branch']->id);

        $this->actingAs($owner, 'web');

        $till = Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('newSale')
            ->call('pick', $seed['service']->uuid)
            ->assertViewHas('pick', fn (array $pick): bool => array_column($pick['employees'], 'uuid') === [$seed['employee']->uuid])
            ->set('pickEmployee', $outsider->uuid)
            ->call('addService')
            ->assertSet('error', 'That employee cannot perform this service at this branch.')
            ->set('pickEmployee', $seed['employee']->uuid)
            ->call('addService')
            ->assertSet('error', '')
            ->assertSee('by Ahmed');

        $sale = Sale::query()->where('uuid', $till->get('sale'))->firstOrFail();
        $line = $sale->items()->sole();

        expect($line->employee_id)->toBe($seed['employee']->id);

        // Changed or cleared through the Action; never on a product line.
        app(ChangeSaleLine::class)->update($sale, $line->uuid, $owner, ['employee' => null]);
        expect($line->fresh()?->employee_id)->toBeNull();

        $productLine = app(AddSaleLine::class)($sale, $owner, ['kind' => 'product', 'product' => $product->uuid]);

        expect(fn () => app(ChangeSaleLine::class)->update($sale, $productLine->uuid, $owner, ['employee' => $seed['employee']->uuid]))
            ->toThrow(SaleFailed::class, 'Only a service line rung up at the till records who performed it here.')
            ->and(fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'product', 'product' => $product->uuid, 'employee' => $seed['employee']->uuid]))
            ->toThrow(SaleFailed::class, 'Only a service line records who performed it.');
    });
});

it('finds a customer by phone only for staff who may see numbers, and adds a walk-in from the till', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        app(SaveCustomer::class)(new CustomerInput(name: 'Layla Hassan', phone: '+9647701234567'), $owner);

        $this->actingAs($owner, 'web');

        $till = Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('newSale')
            ->set('customerSearch', '0770 123 4567');

        expect(array_column($till->viewData('customers'), 'name'))->toBe(['Layla Hassan']);

        // A walk-in added on the spot, through the CRM's own Action, and put
        // straight on the sale.
        $till->call('startNewCustomer')
            ->set('newCustomerName', 'Omar Walk-in')
            ->set('newCustomerPhone', '0750 111 2233')
            ->call('createCustomer')
            ->assertSet('error', '')
            ->assertSet('addingCustomer', false);

        expect(Sale::query()->where('uuid', $till->get('sale'))->firstOrFail()->customer?->name)->toBe('Omar Walk-in');

        // Someone who sees masked numbers only: a phone is not a search key for
        // them, and the name match shows the number masked.
        $cashier = $this->staffWith([Permission::SaleView, Permission::SaleCreate, Permission::CustomerView], 'cashier@alpha.test');
        $this->actingAs($cashier, 'web');

        $masked = Livewire::test(PointOfSale::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('newSale')
            ->set('customerSearch', '07701234567');

        expect($masked->viewData('customers'))->toBe([]);

        $byName = $masked->set('customerSearch', 'Layla')->viewData('customers');

        expect(array_column($byName, 'name'))->toBe(['Layla Hassan'])
            ->and(in_array('+9647701234567', array_column($byName, 'contact'), true))->toBeFalse()
            ->and($masked->html())->not->toContain('7701234567');

        // Adding a customer is the CRM's permission, not the till's.
        $masked->call('startNewCustomer')
            ->set('newCustomerName', 'Not allowed')
            ->call('createCustomer')
            ->assertSet('error', 'You may not add customers.');
    });
});

it('renders every money page in each interface language, and the till locks without POS', function (): void {
    $center = $this->registerCenter('Money Pages', 'owner@money-pages.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->seedBookableCenter();
        $this->grantFinance();
        $this->actingAs($owner);

        $pages = ['/pos', '/pos/settings', '/sales', '/finance', '/finance/receipts', '/finance/shifts', '/finance/expenses', '/payments/gateways'];

        foreach (['en' => 'ltr', 'ar' => 'rtl', 'ckb' => 'rtl'] as $locale => $direction) {
            foreach ($pages as $page) {
                $html = $this->get("http://{$slug}.localhost:8000/manager{$page}?locale={$locale}")
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
                    ->getContent();

                // No raw translation key, no raw state, no "CKB" anywhere.
                expect(preg_match('/\bmanager_(pos|finance)\.[a-z_]+/', strip_tags((string) $html)))->toBe(0, "{$locale} {$page} shows a raw key")
                    ->and(str_contains((string) $html, '>CKB<'))->toBeFalse();
            }
        }

        $this->revokeEntitlement('pos');
        $feature = app(FeatureOffer::class)->for('pos')['name'] ?? 'pos';

        Livewire::test(PointOfSale::class)
            ->assertSee($feature)
            ->assertSee(__('manager_features.ui.eyebrow'))
            ->assertDontSee('Finalize and issue invoice');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
