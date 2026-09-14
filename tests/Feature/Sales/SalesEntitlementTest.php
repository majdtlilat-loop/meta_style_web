<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\SaaS\Models\Plan;
use App\Modules\Catalog\Application\Actions\SaveProduct;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CheckoutJourney;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Sales entitlements and role defaults
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§23–24, 35–36, 57.
|
| The locked decision: `pos` owns sales, checkout and invoice issuing; `printing`
| owns the paper; `payments` and `finance` are Phase 10. There is no `invoices`
| key, and Phase 9 changes no package.
|
*/

it('defines no invoices entitlement and leaves every package exactly as it was', function (): void {
    $catalog = app(EntitlementCatalog::class)->keys();

    expect($catalog)->toContain('pos', 'printing', 'payments', 'finance')
        ->and($catalog)->not->toContain('invoices');

    $this->registerCenter();

    $matrix = [];

    foreach (Plan::query()->get() as $plan) {
        $codes = $plan->entitlementCodes();
        sort($codes);
        $matrix[$plan->code] = $codes;
    }

    ksort($matrix);

    // The pre-Phase-9 matrix. `pos` was already in trial and business;
    // `printing` only in business. Phase 9 granted nothing to anybody.
    expect($matrix)->toBe([
        'business' => ['booking', 'crm', 'customer_accounts', 'finance', 'payments', 'pos', 'printing'],
        'starter' => ['booking', 'customer_accounts'],
        'trial' => ['booking', 'crm', 'customer_accounts', 'pos'],
    ]);
});

it('refuses every sales operation once a center loses POS', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // Works with POS — the baseline this test depends on.
        $this->openShift($seed['branch'], $owner);
        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        $journey = $this->walkInVisit($seed, $owner, ['completed']);

        $this->revokeEntitlement('pos');

        $refusals = [
            'create' => fn () => app(CreateDraftSale::class)($seed['branch']->uuid, $owner),
            'add line' => fn () => app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]),
            'finalize' => fn () => app(FinalizeSale::class)($sale, $owner),
            'checkout' => fn () => app(CheckoutJourney::class)($journey, $owner),
            'shift' => fn () => app(ManageCashierShift::class)->open($seed['branch']->uuid, $owner),
            'product' => fn () => app(SaveProduct::class)(['name' => ['en' => 'Comb'], 'price_minor' => 1000], $owner),
        ];

        foreach ($refusals as $name => $attempt) {
            expect($attempt)->toThrow(EntitlementRequired::class, null, $name);
        }

        expect(Sale::query()->count())->toBe(1)
            ->and($sale->fresh()?->isDraft())->toBeTrue();
    });
});

it('grants the till to cashiers, preparation to reception, everything to managers, and nothing to employees', function (): void {
    $codes = static fn (SystemRole $role): array => array_map(static fn (Permission $p): string => $p->value, $role->permissions());

    $owner = $codes(SystemRole::Owner);
    $manager = $codes(SystemRole::Manager);
    $cashier = $codes(SystemRole::Cashier);
    $host = $codes(SystemRole::Host);
    $employee = $codes(SystemRole::Employee);

    $all = ['sale.view', 'sale.create', 'sale.finalize', 'sale.adjust', 'sale.void', 'invoice.print', 'cashier_shift.manage', 'cashier_shift.supervise', 'product.manage'];

    // Explicit grants, never a bypass (ADR-029).
    expect($owner)->toContain(...$all)
        ->and($manager)->toContain(...$all);

    expect($cashier)->toContain('sale.view', 'sale.create', 'sale.finalize', 'invoice.print', 'cashier_shift.manage');

    expect($host)->toContain('sale.view', 'sale.create');

    // One code per negation: `not->toContain(a, b)` passes when EITHER is absent.
    foreach (['sale.adjust', 'sale.void', 'cashier_shift.supervise', 'product.manage'] as $code) {
        expect(in_array($code, $cashier, true))->toBeFalse("cashier must not hold {$code}");
    }

    foreach (['sale.finalize', 'sale.adjust', 'sale.void', 'invoice.print'] as $code) {
        expect(in_array($code, $host, true))->toBeFalse("host must not hold {$code}");
    }

    foreach ($all as $code) {
        expect($employee)->not->toContain($code);
    }

    // Nine codes, and no `invoice.view`: viewing an invoice IS viewing a sale.
    expect(Permission::codes())->not->toContain('invoice.view')
        ->and(Permission::SaleView->group())->toBe('sales');
});

it('lets a cashier role finalize but not discount or void, through the seeded role', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $cashier = $this->staffWith(SystemRole::Cashier->permissions(), 'cashier@alpha.test');

        $this->openShift($seed['branch'], $cashier);
        $sale = $this->draftSale($seed['branch'], $cashier);
        app(AddSaleLine::class)($sale, $cashier, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        $invoice = app(FinalizeSale::class)($sale, $cashier)->invoice;

        expect($invoice->number)->toStartWith('INV-');

        expect(fn () => app(CloseSale::class)->void($sale, $cashier, 'Oops'))
            ->toThrow(AuthorizationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
