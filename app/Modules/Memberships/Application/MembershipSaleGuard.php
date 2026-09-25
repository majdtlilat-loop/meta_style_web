<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Modules\Sales\Contracts\SaleFinalizationGuard;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;

/**
 * A membership is sold TO somebody: a sale carrying a membership line cannot be
 * published without a customer to give it to
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §11).
 */
final class MembershipSaleGuard implements SaleFinalizationGuard
{
    public function assertFinalizable(Sale $locked): void
    {
        if ($locked->customer_id !== null) {
            return;
        }

        $sellsMembership = SaleItem::query()
            ->where('sale_id', $locked->getKey())
            ->where('kind', SaleItemKind::Offering->value)
            ->where('offering_type', MembershipCatalog::TYPE)
            ->exists();

        if ($sellsMembership) {
            throw SaleFailed::policy('A membership is sold to a customer. Attach the customer before finalizing.');
        }
    }
}
