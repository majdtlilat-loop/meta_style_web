<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Application\SaleVoidGuards;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Events\SaleDraftDiscarded;
use App\Modules\Sales\Domain\Events\SaleVoided;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\SaleMutation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * The two ways a sale stops being open: a draft is DISCARDED, a finalized sale
 * is VOIDED. They are deliberately different operations. Both write their audit
 * entry inside the same transaction as the change (ADR-057).
 *
 * ## Discard deletes a draft
 *
 * A draft has no number and no invoice; it never became financial history. It
 * is deleted with its lines, and the audit log keeps the fact and the totals it
 * had. Keeping a fourth `discarded` status would leave every abandoned cart in
 * every sales query forever (docs/18-SALES.md §5).
 *
 * ## Void preserves everything
 *
 * A finalized sale is never deleted and never returned to draft. Voiding records
 * who, when and why on the SALE; the invoice row is not touched — it cannot be —
 * and renders the void from there. The visit is released, so it can be charged
 * correctly on a new sale. No refund happens here, and none is triggered: a sale
 * whose invoice still holds collected money, or has a payment in flight, is
 * refused by a registered {@see SaleVoidGuards} guard until that money is
 * refunded or resolved explicitly (§§19–21, docs/19-PAYMENTS.md §18).
 */
final class CloseSale
{
    public function __construct(
        private readonly SalesAccess $access,
        private readonly SaleMutation $mutation,
        private readonly SalesAudit $audit,
        private readonly SaleVoidGuards $guards,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function discard(Sale $sale, User $actingUser): void
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not discard sales.');

        DB::connection('tenant')->transaction(function () use ($sale, $actingUser): void {
            $locked = $this->mutation->lock($sale);

            if (! $locked->isDraft()) {
                throw SaleFailed::invalidTransition(
                    'Only a draft can be discarded. A finalized sale is voided instead.',
                    ['status' => $locked->status->value],
                );
            }

            $summary = [
                'source' => $locked->source->value,
                'line_count' => SaleItem::query()->where('sale_id', $locked->getKey())->count(),
                'grand_total_minor' => $locked->grand_total_minor,
            ];

            $locked->delete();

            // Inside the transaction: once the draft is deleted, this entry is
            // the only trace it ever existed, so the two commit together.
            $this->audit->record('sale.discarded', $actingUser, $locked, before: $summary);

            // A module that applied a benefit to this draft gives it back in
            // this same commit. The row is gone, so the event carries the uuid.
            $this->events->dispatch(new SaleDraftDiscarded($locked->uuid));
        });
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function void(Sale $sale, User $actingUser, string $reason, ?CarbonImmutable $now = null): Sale
    {
        $this->access->ensure($actingUser, Permission::SaleVoid, $sale->branch_id, 'You may not void sales.');

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw SaleFailed::policy('A void needs a reason.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var Sale $voided */
        $voided = DB::connection('tenant')->transaction(function () use ($sale, $actingUser, $reason, $at): Sale {
            $locked = $this->mutation->lock($sale);

            if ($locked->status === SaleStatus::Voided) {
                // A double-clicked void: the same outcome, not an error.
                return $locked;
            }

            if ($locked->status !== SaleStatus::Finalized) {
                throw SaleFailed::invalidTransition('Only a finalized sale can be voided. Discard a draft instead.');
            }

            // Anything a higher module knows about this sale that makes a void
            // wrong — money collected, an online payment in flight — refuses
            // here, under the sale lock, before a single column changes.
            $this->guards->assertVoidable($locked);

            $locked->forceFill([
                'status' => SaleStatus::Voided,
                'voided_at' => $at,
                'voided_by_id' => $actingUser->uuid,
                'voided_by_label' => $actingUser->name,
                'void_reason' => $reason,
                // Releases the visit; `service_journey_id` still records it.
                'active_journey_id' => null,
            ])->save();

            // Commits with the void, or not at all (see FinalizeSale).
            $this->audit->record('sale.voided', $actingUser, $locked,
                after: ['status' => SaleStatus::Voided->value],
                before: ['status' => SaleStatus::Finalized->value, 'grand_total_minor' => $locked->grand_total_minor],
                reason: $reason,
                severity: AuditSeverity::Warning,
            );

            // What the sale consumed is given back, and what it sold ends, in
            // this same transaction — by the modules that own them.
            $this->events->dispatch(new SaleVoided((int) $locked->getKey()));

            return $locked;
        });

        return $voided;
    }
}
