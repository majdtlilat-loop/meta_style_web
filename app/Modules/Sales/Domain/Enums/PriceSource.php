<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

/**
 * Where a line's ORIGINAL unit price came from.
 *
 * Journey is not the catalog: a booked visit carries the price the customer was
 * quoted when they booked, and charging today's catalog price instead would
 * silently reprice a reservation (docs/18-SALES.md §38).
 */
enum PriceSource: string
{
    case Catalog = 'catalog';
    case Journey = 'journey';
    case Manual = 'manual';
}
