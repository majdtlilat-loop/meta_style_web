<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Database\AfterCommit;
use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Packages\Domain\Enums\CustomerPackageStatus;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Enums\PackageSource;
use App\Modules\Packages\Domain\Events\PackageActivated;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\CustomerPackageItem;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Payments\Application\InvoiceSettlement;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\Events\PaymentSucceeded;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Events\SaleFinalized;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid-for package line into the customer's package — after the money
 * commits, and repairable from the money.
 *
 * ## When
 *
 * When the invoice that sold it is SETTLED — its successful payments cover the
 * total — or was free (a zero total, at finalization). An unpaid or part-paid
 * invoice activates nothing (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §15).
 *
 * ## Never able to undo real money
 *
 * `PaymentSucceeded` and `SaleFinalized` only SCHEDULE activation for after
 * their transaction commits (`AfterCommit`). The payment, its ledger entry and
 * the invoice commit on their own; a failure here is reported and can never
 * roll them back or turn their success into an error (§1).
 *
 * ## Idempotent, and replayable
 *
 * Activation runs in its own transaction under the SALE lock — the lock every
 * benefit path takes first — and `unique(customer_packages.sale_item_id)`
 * backs it: whichever trigger arrives, however often, one sale line becomes one
 * package. What a lost callback left undone is found from the canonical facts —
 * a finalized, settled sale with a package line and no package — by
 * `reconcile()` (hourly) and `reconcileCustomer()` (immediately before a
 * package is used). A second run writes nothing. Reads never activate: a query
 * that writes is not a query.
 *
 * A purchase settled after the center lost `packages` still activates: the
 * customer paid (§22).
 */
final class ActivatePackages implements Reconciler
{
    public const CUSTOMER_WINDOW_DAYS = 90;

    public function __construct(
        private readonly InvoiceSettlement $settlement,
        private readonly PackageLedger $ledger,
        private readonly PackagesAudit $audit,
        private readonly AfterCommit $afterCommit,
        private readonly Dispatcher $events,
    ) {}

    public function name(): string
    {
        return 'packages';
    }

    public function handlePaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->afterCommit->run('packages.activate_on_payment', function () use ($event): void {
            $saleId = Invoice::query()
                ->whereKey(Payment::query()->whereKey($event->paymentId)->value('invoice_id'))
                ->value('sale_id');

            if ($saleId !== null) {
                $this->syncSale((int) $saleId);
            }
        });
    }

    public function handleSaleFinalized(SaleFinalized $event): void
    {
        $this->afterCommit->run('packages.activate_on_finalize', fn () => $this->syncSale($event->saleId));
    }

    /**
     * Activates every package line of a settled sale that has no package yet.
     * Returns how many it activated.
     */
    public function syncSale(int $saleId, ?CarbonImmutable $now = null): int
    {
        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var int $activated */
        $activated = DB::connection('tenant')->transaction(function () use ($saleId, $at): int {
            /** @var Sale|null $sale */
            $sale = Sale::query()->whereKey($saleId)->lockForUpdate()->first();

            if (! $sale instanceof Sale || $sale->status !== SaleStatus::Finalized || $sale->customer_id === null) {
                return 0;
            }

            /** @var list<SaleItem> $lines */
            $lines = SaleItem::query()
                ->where('sale_id', $sale->getKey())
                ->where('kind', SaleItemKind::Offering->value)
                ->where('offering_type', PackageCatalog::TYPE)
                ->whereNotIn('id', CustomerPackage::query()->select('sale_item_id'))
                ->get()
                ->all();

            if ($lines === []) {
                return 0;
            }

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('sale_id', $sale->getKey())->first();

            if (! $invoice instanceof Invoice || ! $this->settled($invoice)) {
                return 0;
            }

            $count = 0;

            foreach ($lines as $line) {
                $count += $this->activate($sale, $line, $at);
            }

            return $count;
        });

        return $activated;
    }

    public function reconcile(CarbonImmutable $since): int
    {
        return $this->replay($since, null);
    }

    /**
     * Called ONLY by Actions about to use a customer's packages: anything they
     * paid for that a lost callback left unactivated is activated first. Never
     * from a read path.
     */
    public function reconcileCustomer(int $customerId, ?CarbonImmutable $since = null): int
    {
        return $this->replay($since ?? CarbonImmutable::now()->utc()->subDays(self::CUSTOMER_WINDOW_DAYS), $customerId);
    }

    private function replay(CarbonImmutable $since, ?int $customerId): int
    {
        $sales = Sale::query()
            ->where('status', SaleStatus::Finalized->value)
            ->where('finalized_at', '>=', $since)
            ->whereNotNull('customer_id')
            ->whereIn('id', SaleItem::query()->select('sale_id')
                ->where('kind', SaleItemKind::Offering->value)
                ->where('offering_type', PackageCatalog::TYPE)
                ->whereNotIn('id', CustomerPackage::query()->select('sale_item_id')));

        if ($customerId !== null) {
            $sales->where('customer_id', $customerId);
        }

        $repaired = 0;

        foreach ($sales->orderBy('finalized_at')->orderBy('id')->limit(1000)->pluck('id') as $saleId) {
            $repaired += $this->syncSale((int) $saleId);
        }

        return $repaired;
    }

    private function settled(Invoice $invoice): bool
    {
        return $invoice->grand_total_minor === 0
            || $this->settlement->forInvoice($invoice)->state === SettlementState::Paid;
    }

    private function activate(Sale $sale, SaleItem $line, CarbonImmutable $at): int
    {
        /** @var PackageDefinition|null $definition */
        $definition = PackageDefinition::query()
            ->where('uuid', (string) $line->offering_reference)
            ->with(['items.service', 'items.variation'])
            ->first();

        if (! $definition instanceof PackageDefinition) {
            return 0;
        }

        $timezone = (string) (Branch::query()->whereKey($sale->branch_id)->value('timezone') ?: 'UTC');

        /** @var CustomerPackage $package */
        $package = CustomerPackage::query()->create([
            'customer_id' => $sale->customer_id,
            'package_definition_id' => $definition->getKey(),
            'branch_id' => $sale->branch_id,
            'name' => $line->name,
            'price_minor' => $line->unit_price_minor,
            'currency' => $line->currency,
            'validity_days' => $definition->validity_days,
            'sale_id' => $sale->getKey(),
            'sale_item_id' => $line->getKey(),
            'activated_at' => $at,
            'expires_at' => BranchClock::localDayStartAfter($at, $definition->validity_days, $timezone),
            'status' => CustomerPackageStatus::Active,
        ]);

        $actor = Actor::system('packages');

        foreach ($definition->items as $definitionItem) {
            /** @var CustomerPackageItem $item */
            $item = CustomerPackageItem::query()->create([
                'customer_package_id' => $package->getKey(),
                'service_id' => $definitionItem->service_id,
                'service_variation_id' => $definitionItem->service_variation_id,
                'name' => $definitionItem->service?->name,
                'variation_name' => $definitionItem->variation?->name,
                'quantity' => $definitionItem->quantity,
            ]);

            $this->ledger->append($package, $item, PackageMovement::Allocation, $item->quantity,
                PackageSource::Activation, $item->uuid, $at, saleUuid: $sale->uuid, reason: 'Package activated', actor: $actor);
        }

        $this->audit->record('package.activated', $actor, $package, $package->uuid,
            after: ['expires_at' => $package->expires_at->toIso8601String(), 'items' => $definition->items->count()],
            meta: ['sale' => $sale->uuid, 'definition' => $definition->uuid],
        );

        /*
         * Announced inside the activation transaction, identifiers only. This
         * activation is ALREADY after-commit work following settled money
         * (ADR-061); a listener that tells the customer schedules its own write
         * for after this commits, so nothing downstream can undo what they paid
         * for (docs/23-NOTIFICATIONS.md §11).
         */
        $this->events->dispatch(new PackageActivated(
            (int) $package->getKey(),
            (int) $sale->customer_id,
        ));

        return 1;
    }
}
