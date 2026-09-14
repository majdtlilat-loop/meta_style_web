<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Sales\Domain\Data\IssuedInvoice;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\InvoiceNumbers;
use App\Modules\Sales\Domain\InvoicePrefix;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceItem;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Models\SaleItemAddon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishes the invoice for a sale that is being finalized.
 *
 * Runs ONLY inside `FinalizeSale`'s transaction, with the sale row locked and
 * its totals freshly recalculated. Everything it writes is a copy: the invoice
 * never needs the sale lines, the catalog or the branch again to be rendered,
 * and nothing that happens to any of them afterwards reaches it
 * (docs/18-SALES.md §§14–16).
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly InvoiceNumbers $numbers,
        private readonly TenantContext $tenants,
        private readonly InvoiceLinks $links,
    ) {}

    /**
     * The customer link is minted here too, and its plaintext secret returned
     * in the result — the only copy; the row keeps a digest.
     *
     * @throws SaleFailed
     */
    public function issue(Sale $locked, User $actingUser, CarbonImmutable $now): IssuedInvoice
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('InvoiceIssuer::issue() must run inside the finalization transaction.');
        }

        /** @var Branch $branch */
        $branch = Branch::query()->whereKey($locked->branch_id)->firstOrFail();

        $prefix = InvoicePrefix::effective($branch->invoice_prefix, $branch->is_main);

        $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';

        // The BRANCH-LOCAL year: an invoice issued at 00:30 on 1 January in
        // Baghdad belongs to the new year there, whatever UTC says.
        $year = (int) BranchClock::toLocal($now, $timezone)->format('Y');

        $sequence = $this->numbers->next($branch->id, $year);

        $customerName = null;

        if ($locked->customer_id !== null) {
            /** @var Customer|null $customer */
            $customer = Customer::query()->whereKey($locked->customer_id)->first();
            $customerName = $customer?->name;
        }

        /** @var list<SaleItem> $items */
        $items = SaleItem::query()
            ->where('sale_id', $locked->getKey())
            ->with('addons')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        /** @var list<SaleAdjustment> $adjustments */
        $adjustments = SaleAdjustment::query()
            ->where('sale_id', $locked->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->create([
            'sale_id' => $locked->getKey(),
            'branch_id' => $branch->id,
            'number' => InvoicePrefix::format($prefix, $year, $sequence),
            'prefix' => $prefix,
            'sequence_year' => $year,
            'sequence_number' => $sequence,
            'issued_at' => $now,
            'issued_timezone' => $timezone,
            'center_name' => $this->tenants->require()->name,
            'branch_name' => $branch->name,
            'branch_address' => $branch->address,
            'branch_phone' => $branch->phone,
            'customer_name' => $customerName,
            'currency' => $locked->currency,
            'subtotal_minor' => $locked->subtotal_minor,
            'discount_total_minor' => $locked->discount_total_minor,
            'surcharge_total_minor' => $locked->surcharge_total_minor,
            'tax_total_minor' => $locked->tax_total_minor,
            'grand_total_minor' => $locked->grand_total_minor,
            'adjustments' => array_map(static fn (SaleAdjustment $a): array => [
                'type' => $a->type->value,
                'basis_points' => $a->basis_points,
                'amount_minor' => $a->amount_minor,
                'reason' => $a->reason,
            ], $adjustments),
            'issued_by_id' => $actingUser->uuid,
            'issued_by_label' => $actingUser->name,
        ]);

        foreach ($items as $item) {
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->getKey(),
                'position' => $item->position,
                'kind' => $item->kind->value,
                'name' => $item->name,
                'variation_name' => $item->variation_name,
                'addons' => $item->addons->map(static fn (SaleItemAddon $addon): array => [
                    'name' => $addon->name->all(),
                    'unit_price_minor' => $addon->unit_price_minor,
                ])->values()->all(),
                'quantity' => $item->quantity,
                'unit_price_minor' => $item->unit_price_minor,
                'addons_unit_total_minor' => $item->addons_unit_total_minor,
                'line_subtotal_minor' => $item->line_subtotal_minor,
                'discount_allocated_minor' => $item->discount_allocated_minor,
                'line_total_minor' => $item->line_total_minor,
                'currency' => $item->currency,
                'sale_item_id' => $item->getKey(),
                'service_id' => $item->service_id,
                'product_id' => $item->product_id,
            ]);
        }

        return new IssuedInvoice($invoice, $this->links->mint($invoice, $actingUser), replayed: false);
    }
}
