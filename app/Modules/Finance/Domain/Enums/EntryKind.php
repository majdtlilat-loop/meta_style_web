<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 * The four movements of real money the center ledger records.
 *
 * Not "invoiced": issuing an invoice bills an amount and moves no money
 * (docs/20-FINANCE.md §35).
 */
enum EntryKind: string
{
    case Collection = 'collection';
    case Refund = 'refund';
    case Expense = 'expense';
    case ExpenseReversal = 'expense_reversal';

    public function direction(): EntryDirection
    {
        return match ($this) {
            self::Collection, self::ExpenseReversal => EntryDirection::In,
            self::Refund, self::Expense => EntryDirection::Out,
        };
    }
}
