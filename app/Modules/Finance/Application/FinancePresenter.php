<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;

/**
 * The staff view of finance records. Allow-lists; no numeric ids. None of this
 * is ever shown on a customer-facing page (docs/20-FINANCE.md §56).
 */
final class FinancePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function money(int $minor, ?string $currency = null): array
    {
        $code = $currency === null ? Currency::default() : (Currency::tryFrom($currency) ?? Currency::default());

        return Money::fromMinor($minor, $code)->toArray(app()->getLocale());
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(FinanceEntry $entry): array
    {
        return [
            'uuid' => $entry->uuid,
            'direction' => $entry->direction->value,
            'kind' => $entry->kind->value,
            'amount' => $this->money($entry->amount_minor, $entry->currency),
            'method' => $entry->method->value,
            'provider' => $entry->provider,
            'source_type' => $entry->source_type->value,
            'source' => $entry->source_uuid,
            'label' => $entry->label,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function expense(Expense $expense): array
    {
        $locale = app()->getLocale();

        return [
            'uuid' => $expense->uuid,
            'category' => $expense->relationLoaded('category') && $expense->category !== null
                ? ['uuid' => $expense->category->uuid, 'name' => $expense->category->name->get($locale)]
                : null,
            'amount' => $this->money($expense->amount_minor, $expense->currency),
            'method' => $expense->method->value,
            'occurred_at' => $expense->occurred_at->toIso8601String(),
            'reference' => $expense->reference,
            'payee' => $expense->payee_label,
            'description' => $expense->description,
            'from_drawer' => $expense->cashier_shift_id !== null,
            'status' => $expense->status->value,
            'created_by' => $expense->created_by_label,
            'voided_at' => $expense->voided_at?->toIso8601String(),
            'voided_by' => $expense->voided_by_label,
            'void_reason' => $expense->void_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function category(ExpenseCategory $category): array
    {
        return [
            'uuid' => $category->uuid,
            'name' => $category->name->get(app()->getLocale()),
            'names' => $category->name->all(),
            'sort_order' => $category->sort_order,
            'archived' => $category->archived_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconciliation(ShiftReconciliation $reconciliation): array
    {
        $currency = $reconciliation->currency;

        return [
            'uuid' => $reconciliation->uuid,
            'opening_cash' => $this->money($reconciliation->opening_cash_minor, $currency),
            'cash_collected' => $this->money($reconciliation->cash_collected_minor, $currency),
            'cash_refunded' => $this->money($reconciliation->cash_refunded_minor, $currency),
            'cash_expenses' => $this->money($reconciliation->cash_expenses_minor, $currency),
            'cash_expense_reversals' => $this->money($reconciliation->cash_expense_reversals_minor, $currency),
            'expected_cash' => $this->money($reconciliation->expected_cash_minor, $currency),
            'counted_cash' => $this->money($reconciliation->counted_cash_minor, $currency),
            'variance' => $this->money($reconciliation->variance_minor, $currency),
            'note' => $reconciliation->note,
            'reconciled_by' => $reconciliation->reconciled_by_label,
            'reconciled_at' => $reconciliation->reconciled_at->toIso8601String(),
        ];
    }
}
