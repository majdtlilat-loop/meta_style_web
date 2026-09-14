<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\PublicInvoice;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Sales tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/18-SALES.md §50.
|
| One database per center. Sales, invoices and shifts carry no `tenant_id`,
| because there is nothing to disambiguate — and a sales query with no tenant
| bound must FAIL rather than answer from somewhere.
|
*/

/**
 * @return array{sale: Sale, invoice: Invoice, token: string}
 */
function siIssue(string $customerName): array
{
    $seed = test()->seedBookableCenter();
    $owner = test()->ownerWithCatalogAccess();

    test()->openShift($seed['branch'], $owner);

    $customer = test()->seedCustomer($customerName, '0750 '.random_int(100, 999).' '.random_int(1000, 9999));

    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);

    $issued = app(FinalizeSale::class)($sale, $owner);

    return [
        'sale' => $sale,
        'invoice' => $issued->invoice,
        'token' => (string) $issued->shareToken,
    ];
}

it('keeps two centers\' sales and invoices apart even when their ids and numbers collide', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => siIssue('Alpha customer'));
    $b = $this->asCenter($beta['tenant'], fn (): array => siIssue('Beta customer'));

    // The same row id and the same invoice NUMBER, in two databases.
    expect($a['sale']->id)->toBe($b['sale']->id)
        ->and($a['invoice']->number)->toBe($b['invoice']->number)
        ->and($a['invoice']->uuid)->not->toBe($b['invoice']->uuid);

    $this->asCenter($alpha['tenant'], function (): void {
        expect(Sale::query()->count())->toBe(1)
            ->and(Invoice::query()->firstOrFail()->customer_name)->toBe('Alpha customer');
    });

    $this->asCenter($beta['tenant'], function (): void {
        expect(Sale::query()->count())->toBe(1)
            ->and(Invoice::query()->firstOrFail()->customer_name)->toBe('Beta customer');
    });
});

it('never resolves one center\'s invoice token inside another center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $a = $this->asCenter($alpha['tenant'], fn (): array => siIssue('Alpha customer'));

    $this->asCenter($alpha['tenant'], function () use ($a): void {
        expect(app(PublicInvoice::class)->forToken($a['token']))->not->toBeNull();
    });

    $this->asCenter($beta['tenant'], function () use ($a): void {
        expect(app(PublicInvoice::class)->forToken($a['token']))->toBeNull();
    });
});

it('numbers each center independently', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        siIssue('One');
        siIssue('Two');
        siIssue('Three');
    });

    $betaFirst = $this->asCenter($beta['tenant'], fn (): array => siIssue('Beta first'));

    // Beta starts at 000001 regardless of how busy Alpha was.
    expect($betaFirst['invoice']->sequence_number)->toBe(1);

    $this->asCenter($beta['tenant'], function (): void {
        expect((int) DB::connection('tenant')->table('invoice_sequences')->sum('last_number'))->toBe(1);
    });
});

it('keeps cashier shifts and products inside one center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        siIssue('Alpha customer');
        $this->seedProduct('Alpha shampoo', 9000);
    });

    $this->asCenter($beta['tenant'], function (): void {
        expect(CashierShift::query()->count())->toBe(0)
            ->and(Product::query()->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0)
            ->and(InvoiceShareLink::query()->count())->toBe(0);
    });
});

it('fails closed when no center is bound, and leaves none bound after sales work', function (): void {
    $center = $this->registerCenter();

    expect(fn (): int => Sale::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => Invoice::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => CashierShift::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): ?array => app(PublicInvoice::class)->forToken(str_repeat('a', 64)))->toThrow(TenantConnectionNotInitialized::class);

    $this->asCenter($center['tenant'], fn (): array => siIssue('Sara'));

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('puts every sales table in the center database, with no tenant column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        foreach (['products', 'cashier_shifts', 'sales', 'sale_items', 'sale_item_addons', 'sale_adjustments', 'invoice_sequences', 'invoices', 'invoice_items', 'invoice_share_links'] as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue()
                ->and(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))->toBeFalse();
        }

        // And nothing financial on the operational tables (§1).
        foreach (['appointments', 'appointment_items', 'service_journeys', 'journey_stages', 'queue_tickets'] as $table) {
            foreach (['sale_id', 'invoice_id', 'grand_total_minor', 'paid_minor', 'payment_status'] as $column) {
                expect(Schema::connection('tenant')->hasColumn($table, $column))->toBeFalse();
            }
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
