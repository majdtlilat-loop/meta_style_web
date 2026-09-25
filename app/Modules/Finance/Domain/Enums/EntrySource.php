<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 * The domain record a ledger entry is the money movement OF. Together with the
 * source uuid and the kind, unique: one movement per fact, whatever retries.
 */
enum EntrySource: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Expense = 'expense';
}
