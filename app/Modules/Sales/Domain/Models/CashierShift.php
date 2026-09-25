<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One cashier's working session at one branch.
 *
 * An operational period, not a cash drawer: it attributes finalized sales to a
 * person and a time window, and it is the seam Phase 10 reconciliation will
 * count against. No float, no counted cash, no variance (docs/18-SALES.md §13).
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property int $user_id
 * @property int|null $active_user_id
 * @property ShiftStatus $status
 * @property int|null $opening_cash_minor
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $opening_note
 * @property string|null $closing_note
 * @property string|null $closed_by_id
 * @property string|null $closed_by_label
 */
final class CashierShift extends Model
{
    use UsesTenantConnection;

    protected $table = 'cashier_shifts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opening_cash_minor' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $shift): void {
            $shift->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }
}
