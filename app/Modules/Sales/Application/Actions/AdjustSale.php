<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\SalesAudit;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\SaleMutation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Sale-level changes to a DRAFT: manual discounts and surcharges, and who the
 * sale is for.
 *
 * ## Manual adjustments only
 *
 * Fixed and percentage discounts, fixed surcharges — each with `sale.adjust`,
 * a reason, and an audit row. No promo codes. A customer's entitled benefit —
 * points, a member's price, a package session — is not a manual discount: it
 * arrives through `SaleBenefits` from the module that owns it, and this class
 * neither adds nor removes one. A discount may take a sale to zero and never
 * below it, which the pricing service enforces inside the same transaction as
 * the change (docs/18-SALES.md §12).
 */
final class AdjustSale
{
    private const MAX_ADJUSTMENTS = 10;

    public function __construct(
        private readonly SalesAccess $access,
        private readonly SaleMutation $mutation,
        private readonly SalesAudit $audit,
    ) {}

    /**
     * @param  int  $value  basis points for a percentage, minor units otherwise
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function add(Sale $sale, User $actingUser, AdjustmentType $type, int $value, string $reason): SaleAdjustment
    {
        $this->access->ensure($actingUser, Permission::SaleAdjust, $sale->branch_id, 'You may not apply discounts or surcharges.');

        if ($type->isBenefit()) {
            // A customer's benefit is applied by the module that owns it,
            // through `SaleBenefits`, with its own record — never typed in.
            throw SaleFailed::policy('A customer benefit is applied from their benefits, not as a manual discount.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw SaleFailed::policy('A discount or surcharge needs a reason.');
        }

        [, $adjustment] = $this->mutation->apply($sale, function (Sale $locked) use ($type, $value, $reason, $actingUser): SaleAdjustment {
            $count = SaleAdjustment::query()->where('sale_id', $locked->getKey())->count();

            if ($count >= self::MAX_ADJUSTMENTS) {
                throw SaleFailed::policy('A sale may carry at most '.self::MAX_ADJUSTMENTS.' adjustments.');
            }

            /** @var SaleAdjustment $adjustment */
            $adjustment = SaleAdjustment::query()->create([
                'sale_id' => $locked->getKey(),
                'type' => $type,
                'basis_points' => $type->isPercentage() ? $value : null,
                // The pricing service validates both shapes and resolves the
                // percentage amount in the recalculation that follows.
                'amount_minor' => $type->isPercentage() ? 0 : $value,
                'reason' => $reason,
                'position' => $count,
                'created_by_id' => $actingUser->uuid,
                'created_by_label' => $actingUser->name,
            ]);

            return $adjustment;
        });

        $adjustment->refresh();

        $this->audit->record('sale.adjustment_added', $actingUser, $sale, after: [
            'adjustment' => $adjustment->uuid,
            'type' => $type->value,
            'basis_points' => $adjustment->basis_points,
            'amount_minor' => $adjustment->amount_minor,
        ], reason: $reason, severity: AuditSeverity::Notice);

        return $adjustment;
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function remove(Sale $sale, string $adjustmentUuid, User $actingUser): void
    {
        $this->access->ensure($actingUser, Permission::SaleAdjust, $sale->branch_id, 'You may not apply discounts or surcharges.');

        [, $removed] = $this->mutation->apply($sale, function (Sale $locked) use ($adjustmentUuid): array {
            /** @var SaleAdjustment|null $adjustment */
            $adjustment = SaleAdjustment::query()
                ->where('sale_id', $locked->getKey())
                ->where('uuid', $adjustmentUuid)
                ->first();

            if (! $adjustment instanceof SaleAdjustment) {
                throw SaleFailed::policy('That adjustment is not on this sale.');
            }

            if ($adjustment->type->isBenefit()) {
                // Deleting it here would leave the points spent or the package
                // session used with nothing to show for it. Its owner withdraws it.
                throw SaleFailed::policy('Withdraw a customer benefit from their benefits, so it is given back.');
            }

            $summary = [
                'adjustment' => $adjustment->uuid,
                'type' => $adjustment->type->value,
                'amount_minor' => $adjustment->amount_minor,
            ];

            $adjustment->delete();

            return $summary;
        });

        $this->audit->record('sale.adjustment_removed', $actingUser, $sale, before: $removed, severity: AuditSeverity::Notice);
    }

    /**
     * Attaches, replaces or clears the customer. The SALE's customer only: a sale
     * opened from a visit defaults to the visit's customer, and changing it here
     * never rewrites the visit (docs/18-SALES.md §39).
     *
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function customer(Sale $sale, User $actingUser, ?string $customerUuid): Sale
    {
        $this->access->ensure($actingUser, Permission::SaleCreate, $sale->branch_id, 'You may not change sales.');

        $customer = null;

        if ($customerUuid !== null && $customerUuid !== '') {
            /** @var Customer|null $customer */
            $customer = Customer::query()->where('uuid', $customerUuid)->whereNull('archived_at')->first();

            if (! $customer instanceof Customer) {
                throw SaleFailed::policy('That customer could not be found.');
            }
        }

        $had = $sale->customer_id !== null;

        [$locked] = $this->mutation->apply($sale, function (Sale $locked) use ($customer): void {
            $benefits = SaleAdjustment::query()
                ->where('sale_id', $locked->getKey())
                ->where('type', AdjustmentType::BenefitDiscount->value)
                ->exists();

            // A benefit belongs to the customer it was applied for — their
            // points, their package. Changing whose sale this is underneath it
            // would hand one customer's benefit to another.
            if ($benefits && $locked->customer_id !== $customer?->id) {
                throw SaleFailed::policy('Withdraw the customer\'s benefits before changing who the sale is for.');
            }

            $locked->forceFill(['customer_id' => $customer?->id])->save();
        });

        // Whether there is a customer — never who. An audit row is readable by
        // more people than the customer record (CLAUDE.md).
        $this->audit->record('sale.customer_changed', $actingUser, $locked,
            after: ['has_customer' => $customer !== null],
            before: ['has_customer' => $had],
        );

        return $locked;
    }
}
