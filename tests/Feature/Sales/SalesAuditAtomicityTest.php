<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceItem;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| The money and its audit commit together
|--------------------------------------------------------------------------
|
| docs/08-AUDIT-SECURITY.md §7, docs/18-SALES.md §18.
|
| The authoritative audit facts of a finalization, a void and a discard are
| written INSIDE the transaction that changes the sale. So an audit store that
| fails cannot leave an invoice published and reported as a success with no
| accountable record behind it: the whole operation rolls back and the caller
| sees the error.
|
| The failure is injected where a real one would surface — the INSERT into the
| tenant's audit_logs — and only for the one action under test.
|
*/

/**
 * Makes the tenant audit store refuse one action while `$failing` is true.
 */
function aaFailAudit(string $action, bool &$failing): void
{
    Event::listen('eloquent.creating: '.TenantAuditLog::class, function (TenantAuditLog $entry) use ($action, &$failing): void {
        if ($failing && $entry->action === $action) {
            throw new RuntimeException('The audit store is unavailable.');
        }
    });
}

function aaDraft(array $seed, $owner): Sale
{
    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);

    return $sale;
}

it('publishes nothing when the finalization audit cannot be written, and finalizes cleanly once it can', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);
        $sale = aaDraft($seed, $owner);

        $failing = true;
        // The SECOND of the two entries fails, so the first — already written
        // in the same transaction — must roll back with everything else.
        aaFailAudit('invoice.issued', $failing);

        expect(fn () => app(FinalizeSale::class)($sale, $owner))
            ->toThrow(RuntimeException::class, 'audit store is unavailable');

        $fresh = $sale->fresh();

        expect($fresh?->status)->toBe(SaleStatus::Draft)
            ->and($fresh?->finalized_at)->toBeNull()
            ->and($fresh?->cashier_shift_id)->toBeNull()
            ->and(Invoice::query()->count())->toBe(0)
            ->and(InvoiceItem::query()->count())->toBe(0)
            ->and(InvoiceShareLink::query()->count())->toBe(0)
            // No number was consumed by the attempt.
            ->and(DB::connection('tenant')->table('invoice_sequences')->count())->toBe(0)
            ->and(TenantAuditLog::query()->whereIn('action', ['sale.finalized', 'invoice.issued'])->count())->toBe(0);

        // The store recovers. The same draft finalizes, as a first publication,
        // with the first number and exactly one of each audit fact.
        $failing = false;

        $issued = app(FinalizeSale::class)($sale, $owner);

        expect($issued->replayed)->toBeFalse()
            ->and($issued->invoice->sequence_number)->toBe(1)
            ->and($sale->fresh()?->status)->toBe(SaleStatus::Finalized)
            ->and(TenantAuditLog::query()->where('action', 'sale.finalized')->count())->toBe(1)
            ->and(TenantAuditLog::query()->where('action', 'invoice.issued')->count())->toBe(1);
    });
});

it('leaves a sale finalized when the void audit cannot be written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);
        $sale = aaDraft($seed, $owner);
        app(FinalizeSale::class)($sale, $owner);

        $failing = true;
        aaFailAudit('sale.voided', $failing);

        expect(fn () => app(CloseSale::class)->void($sale, $owner, 'Wrong customer'))
            ->toThrow(RuntimeException::class, 'audit store is unavailable');

        expect($sale->fresh()?->status)->toBe(SaleStatus::Finalized)
            ->and($sale->fresh()?->void_reason)->toBeNull()
            ->and($sale->fresh()?->active_journey_id)->toBe($sale->active_journey_id);
    });
});

it('keeps a draft when the audit that would be its only trace cannot be written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sale = aaDraft($seed, $owner);

        $failing = true;
        aaFailAudit('sale.discarded', $failing);

        expect(fn () => app(CloseSale::class)->discard($sale, $owner))
            ->toThrow(RuntimeException::class, 'audit store is unavailable');

        expect(Sale::query()->whereKey($sale->id)->exists())->toBeTrue();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
