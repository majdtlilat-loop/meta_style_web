<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * A provider's answer, translated into the only four things settlement needs to
 * know. Each adapter maps its own vocabulary onto these — FIB's `PAID`, `UNPAID`
 * and `DECLINED` with its declining reason — and nothing else leaks upward.
 */
enum ProviderState: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
}
