<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 *   posted → voided
 *
 * No draft: a draft expense is not money that left, and keeping one beside the
 * posted truth is a second list somebody has to remember to exclude
 * (docs/20-FINANCE.md §40).
 */
enum ExpenseStatus: string
{
    case Posted = 'posted';
    case Voided = 'voided';
}
