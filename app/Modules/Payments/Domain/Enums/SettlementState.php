<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Derived, never stored. There is no mutable "payment status" column on an
 * invoice: the invoice is immutable, and a convenience column would be a second
 * copy of the truth waiting to disagree (docs/19-PAYMENTS.md §8).
 */
enum SettlementState: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
}
