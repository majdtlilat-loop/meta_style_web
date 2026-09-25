<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Models;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Finance\Domain\Enums\ExpenseStatus;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Money the center paid out. Posted, then possibly voided with a reason; the
 * amount, category, branch and method never change once posted
 * (docs/20-FINANCE.md §§39–40).
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property int $expense_category_id
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $occurred_at
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $payee_label
 * @property string $description
 * @property int|null $cashier_shift_id
 * @property ExpenseStatus $status
 * @property string|null $idempotency_token
 * @property string|null $created_by_id
 * @property string|null $created_by_label
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_id
 * @property string|null $voided_by_label
 * @property string|null $void_reason
 */
final class Expense extends Model
{
    use UsesTenantConnection;

    protected $table = 'expenses';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'method' => PaymentMethod::class,
            'status' => ExpenseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $expense): void {
            $expense->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function money(): Money
    {
        return Money::fromMinor($this->amount_minor, Currency::tryFrom($this->currency) ?? Currency::default());
    }
}
