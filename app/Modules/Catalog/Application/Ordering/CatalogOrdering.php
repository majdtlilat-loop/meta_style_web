<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Ordering;

use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Takes the catalog's ordering locks and hands back the current layout.
 *
 * ONE ORDER FOR THE WHOLE LIBRARY. Categories are ordered among themselves,
 * and a service's `sort_order` is its position in the whole library: the first
 * category's services, then the second's, and the uncategorised ones last. So
 * every flat list that orders by (sort_order, id) — the till, the calendar,
 * the queue — shows services grouped the way the owner arranged them, and the
 * menu, which groups by category, still gets each category's own order.
 *
 * The order is always re-derived from the LOCKED rows, never from a list a
 * client sent. A caller states one intent ("put X at position N of list Y"),
 * this class clamps it and renumbers contiguously. A stale browser just
 * re-renders.
 *
 * Locks are taken in one fixed order — categories, then services — by every
 * writer, so two reorders serialise instead of deadlocking.
 */
final class CatalogOrdering
{
    /**
     * @throws LogicException outside a transaction, where a row lock protects nothing
     */
    public function lock(): CatalogLayout
    {
        if (DB::connection('tenant')->transactionLevel() === 0) {
            throw new LogicException('Catalog ordering must be changed inside a tenant transaction.');
        }

        /** @var list<ServiceCategory> $categories */
        $categories = ServiceCategory::query()
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'uuid', 'sort_order'])
            ->all();

        /** @var list<Service> $services */
        $services = Service::query()
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'uuid', 'service_category_id', 'sort_order'])
            ->all();

        return new CatalogLayout($categories, $services);
    }
}
