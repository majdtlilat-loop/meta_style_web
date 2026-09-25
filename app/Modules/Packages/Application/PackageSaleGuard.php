<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Modules\Sales\Contracts\SaleFinalizationGuard;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;

/**
 * A package is sold TO somebody: a sale carrying a package line cannot be
 * published without a customer to give it to
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §15).
 */
final class PackageSaleGuard implements SaleFinalizationGuard
{
    public function assertFinalizable(Sale $locked): void
    {
        if ($locked->customer_id !== null) {
            return;
        }

        $sellsPackage = SaleItem::query()
            ->where('sale_id', $locked->getKey())
            ->where('kind', SaleItemKind::Offering->value)
            ->where('offering_type', PackageCatalog::TYPE)
            ->exists();

        if ($sellsPackage) {
            throw SaleFailed::policy('A package is sold to a customer. Attach the customer before finalizing.');
        }
    }
}
