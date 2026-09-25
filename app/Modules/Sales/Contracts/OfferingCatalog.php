<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Domain\Data\OfferingItem;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * Something another module sells through the till.
 *
 * Memberships and service packages are bought like a haircut: a sale line, an
 * invoice, a payment. A module that sells something implements this, tagged
 * `Offerings::TAG`, and Sales prices the line from the item it returns — never
 * from the browser — without knowing what the item is
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§11, 15).
 *
 * The module enforces its own entitlement and availability here. What the item
 * does once it is paid for is the module's business, on its own events.
 */
interface OfferingCatalog
{
    /** Stored on the line as `offering_type`. Lowercase, at most 32 characters. */
    public function type(): string;

    /**
     * What can be sold right now, for the till's search.
     *
     * @return list<OfferingItem>
     */
    public function available(): array;

    /**
     * One item, priced now, for a line on `$sale`.
     *
     * @throws SaleFailed when it cannot be sold
     */
    public function offer(string $reference, Sale $sale): OfferingItem;
}
