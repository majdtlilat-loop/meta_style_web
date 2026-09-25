<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

enum SaleItemKind: string
{
    case Service = 'service';
    case Product = 'product';

    /**
     * A line typed at the till with its own name and price. Permission-gated
     * and reasoned, because it is a price nobody's catalog agreed to
     * (docs/18-SALES.md §5).
     */
    case Custom = 'custom';

    /**
     * Something another module sells through the till — a membership plan, a
     * service package — resolved through `Sales\Contracts\OfferingCatalog`.
     * Sales prices and invoices it like any line and never knows what it is
     * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§11, 15).
     */
    case Offering = 'offering';
}
