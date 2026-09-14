<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\InvoiceIssuer;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Application\VisitLines;
use App\Modules\Sales\Domain\Data\IssuedInvoice;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\SaleMutation;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Finalizes a draft and publishes its invoice. Atomically, or not at all.
 *
 *     BEGIN
 *       lock the sale                       FOR UPDATE
 *       already finalized?  → return THAT invoice          (a double click)
 *       voided?             → refuse
 *       a visit's sale?     → the visit must be COMPLETED; add any performed
 *                             service not yet on the bill
 *       lock the cashier's open shift       FOR UPDATE
 *       re-price every line from its snapshot              (never the catalog)
 *       validate: ≥ 1 line, one currency, total ≥ 0
 *       allocate the number                 locked sequence row
 *       write the Invoice + lines + share link digest      (all copies)
 *       mark the sale finalized, stamp the shift
 *       write `sale.finalized` + `invoice.issued` audit
 *     COMMIT
 *
 * If any step throws, the transaction rolls back and NOTHING changes — including
 * the number, because the sequence increment is inside the same transaction.
 * Two desks finalizing the same sale serialise on the sale lock; the second
 * finds it finalized and receives the same invoice (docs/18-SALES.md §§18, 47).
 *
 * ## The audit commits with the money
 *
 * The two authoritative audit facts are written INSIDE the transaction, to the
 * tenant's own `audit_logs` on the same connection. A published invoice whose
 * audit write failed would otherwise be a financial fact nobody is accountable
 * for, reported to the till as a success. Now an audit failure rolls the whole
 * finalization back and the caller sees the error: never a split brain
 * (docs/08-AUDIT-SECURITY.md §7). The audit still records who and when, not the
 * lines or totals history — the sale and invoice rows are that record.
 *
 * ## A visit is invoiced once it is over
 *
 * A draft may be prepared while the visit is in progress, so the desk can
 * preview it. It is not PUBLISHED until the visit is completed: an invoice
 * issued mid-visit would miss every service performed afterwards, and the
 * one-live-sale rule would leave nowhere honest to charge them. An abandoned
 * visit is not checked out at all. There is no partial, split or progress
 * invoice in Phase 9 (§7).
 *
 * ## The shift is required
 *
 * Every finalized sale is attributed to the cashier's open shift at that branch.
 * Without it, Phase 10's reconciliation would meet takings nobody was
 * accountable for (§13).
 */
final class FinalizeSale
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly SaleMutation $mutation,
        private readonly InvoiceIssuer $issuer,
        private readonly VisitLines $visitLines,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function __invoke(Sale $sale, User $actingUser, ?CarbonImmutable $now = null): IssuedInvoice
    {
        $this->access->ensure($actingUser, Permission::SaleFinalize, $sale->branch_id, 'You may not finalize sales.');

        $at = ($now ?? CarbonImmutable::now())->utc();

        try {
            /** @var IssuedInvoice $issued */
            $issued = DB::connection('tenant')->transaction(function () use ($sale, $actingUser, $at): IssuedInvoice {
                $locked = $this->mutation->lock($sale);

                if ($locked->status === SaleStatus::Finalized) {
                    return new IssuedInvoice($this->invoiceOf($locked), null, replayed: true);
                }

                if ($locked->status === SaleStatus::Voided) {
                    throw SaleFailed::invalidTransition('That sale was voided and cannot be finalized.');
                }

                $visitLinesAdded = $this->closeOutVisit($locked);

                $shift = $this->openShift($actingUser, $locked->branch_id);

                if (SaleItem::query()->where('sale_id', $locked->getKey())->doesntExist()) {
                    throw SaleFailed::policy('An empty sale cannot be finalized.');
                }

                // Authoritative. Whatever the screen showed, THIS is the total.
                $this->mutation->recalculate($locked);

                $issued = $this->issuer->issue($locked, $actingUser, $at);
                $invoice = $issued->invoice;

                $locked->forceFill([
                    'status' => SaleStatus::Finalized,
                    'finalized_at' => $at,
                    'finalized_by_id' => $actingUser->uuid,
                    'finalized_by_label' => $actingUser->name,
                    'cashier_shift_id' => $shift->getKey(),
                ])->save();

                // Same transaction: these commit with the invoice or not at all.
                $this->audit->record('sale.finalized', $actingUser, $locked, after: [
                    'status' => SaleStatus::Finalized->value,
                    'grand_total_minor' => $locked->grand_total_minor,
                    'currency' => $locked->currency,
                    'shift' => $shift->uuid,
                ], meta: array_filter([
                    'invoice' => $invoice->uuid,
                    'visit_lines_added' => $visitLinesAdded > 0 ? $visitLinesAdded : null,
                ], static fn (mixed $value): bool => $value !== null));

                $this->audit->record('invoice.issued', $actingUser, $locked, after: [
                    'invoice' => $invoice->uuid,
                    'number' => $invoice->number,
                    'grand_total_minor' => $invoice->grand_total_minor,
                ]);

                return $issued;
            });
        } catch (UniqueConstraintViolationException $e) {
            // `unique(invoices.sale_id)` or `unique(invoices.number)`. The first
            // means another desk published this sale a moment ago; the second
            // means a prefix now collides with history.
            $published = Invoice::query()->where('sale_id', $sale->getKey())->first();

            if ($published instanceof Invoice) {
                return new IssuedInvoice($published, null, replayed: true);
            }

            throw SaleFailed::policy(
                'That invoice number is already taken. Check this branch\'s invoice prefix.',
            );
        }

        return $issued;
    }

    /**
     * For a visit's sale: refuses unless the visit is completed, then adds any
     * performed service the draft does not carry yet.
     *
     * A completed visit is terminal and every one of its stages is settled, so
     * the set of performed services can no longer change under this read. An
     * active visit might complete a moment from now; it is refused, and the
     * desk tries again once it has.
     *
     * @throws SaleFailed
     */
    private function closeOutVisit(Sale $locked): int
    {
        if ($locked->service_journey_id === null) {
            return 0;
        }

        /** @var ServiceJourney|null $journey */
        $journey = ServiceJourney::query()->whereKey($locked->service_journey_id)->first();

        if (! $journey instanceof ServiceJourney) {
            throw SaleFailed::policy('That visit no longer exists.');
        }

        if ($journey->status === JourneyStatus::Aborted) {
            throw SaleFailed::invalidTransition(
                'That visit was abandoned and cannot be checked out. Discard this sale.',
                ['journey_status' => $journey->status->value],
            );
        }

        if ($journey->status !== JourneyStatus::Completed) {
            throw SaleFailed::invalidTransition(
                'This visit is still in progress. Complete the visit before finalizing its sale.',
                ['journey_status' => $journey->status->value],
            );
        }

        return $this->visitLines->sync($locked, $journey);
    }

    private function invoiceOf(Sale $locked): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('sale_id', $locked->getKey())->firstOrFail();

        return $invoice;
    }

    private function openShift(User $actingUser, int $branchId): CashierShift
    {
        /** @var CashierShift|null $shift */
        $shift = CashierShift::query()
            ->where('active_user_id', $actingUser->getKey())
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (! $shift instanceof CashierShift || $shift->status !== ShiftStatus::Open) {
            throw SaleFailed::policy('Open your cashier shift at this branch before finalizing a sale.');
        }

        return $shift;
    }
}
