<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One customer's purchases — ISSUED sales and their invoices — for the staff
 * customer page (Manager CRM).
 *
 * `sale.view` and the viewer's branch scope, never `pos`: what a center already
 * charged stays readable after a downgrade (docs/18-SALES.md §55). Drafts are a
 * cart, not a purchase, and are left out. Nothing here adds anything up — each
 * row shows the total `SalePricing` already wrote.
 */
final class CustomerProfileSales
{
    public const LIMIT = 50;

    /**
     * @return list<Sale>
     *
     * @throws AuthorizationException
     */
    public function forCustomer(User $viewer, int $customerId, int $limit = self::LIMIT): array
    {
        if (! $viewer->hasPermission(Permission::SaleView)) {
            throw new AuthorizationException(__('manager_customers.errors.may_not_view_sales'));
        }

        /** @var list<Sale> $sales */
        $sales = $viewer->branchScope()->applyTo(
            Sale::query()
                ->with(['invoice', 'branch'])
                ->where('customer_id', $customerId)
                ->whereIn('status', [SaleStatus::Finalized->value, SaleStatus::Voided->value]),
        )
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->all();

        return $sales;
    }
}
