<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Kernel\Reporting\ReadTarget;

/**
 * The customer-facing category of services, for grouping a report.
 *
 * Operational modules route by department and never read menu categories
 * (ADR-037); a report that wants "services performed by category" asks the
 * Catalog here and groups the services it already has.
 */
interface ReportServiceCategories
{
    /**
     * @param  list<int>  $serviceIds
     * @return array<int, array{id: int, name: string}|null> service id => its category, null when it has none
     */
    public function forServices(array $serviceIds, ReadTarget $target): array;
}
