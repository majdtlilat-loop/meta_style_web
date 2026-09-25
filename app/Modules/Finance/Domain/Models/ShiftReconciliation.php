<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Finance\Domain\Concerns\AppendOnlyRecord;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The drawer count at a shift's close, with snapshots of everything the expected
 * amount was computed from. Written once (docs/20-FINANCE.md §§31–33).
 *
 * @property int $id
 * @property string $uuid
 * @property int $cashier_shift_id
 * @property int $branch_id
 * @property string $currency
 * @property int $opening_cash_minor
 * @property int $cash_collected_minor
 * @property int $cash_refunded_minor
 * @property int $cash_expenses_minor
 * @property int $cash_expense_reversals_minor
 * @property int $expected_cash_minor
 * @property int $counted_cash_minor
 * @property int $variance_minor
 * @property string|null $note
 * @property string|null $reconciled_by_id
 * @property string|null $reconciled_by_label
 * @property Carbon $reconciled_at
 */
final class ShiftReconciliation extends Model
{
    use AppendOnlyRecord;
    use UsesTenantConnection;

    protected $table = 'cashier_shift_reconciliations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_cash_minor' => 'integer',
            'cash_collected_minor' => 'integer',
            'cash_refunded_minor' => 'integer',
            'cash_expenses_minor' => 'integer',
            'cash_expense_reversals_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'counted_cash_minor' => 'integer',
            'variance_minor' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $reconciliation): void {
            $reconciliation->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
