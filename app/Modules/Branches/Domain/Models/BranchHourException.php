<?php

declare(strict_types=1);

namespace App\Modules\Branches\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A specific date that does not follow the weekly pattern.
 *
 * Eid, a public holiday, a private event, a late opening for a wedding party.
 * Closed and "open at unusual times" are one table because they answer the same
 * question and a UI lists them together.
 *
 * This is NOT leave management. Staff absence is an HR concern with an approval
 * workflow, entitlements and balances, and it belongs to whichever phase
 * actually needs it — not smuggled in here because the shape looks similar
 * (docs/13-ROADMAP.md Phase 4 §1).
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $date
 * @property bool $is_closed
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property string|null $note
 */
final class BranchHourException extends Model
{
    use UsesTenantConnection;

    protected $table = 'branch_hour_exceptions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // A plain date. Casting to datetime would make "Eid" a moment in
            // time, which is exactly the bug a per-branch timezone creates.
            'date' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Exceptions from today onward. The past is history, not schedule.
     *
     * @param  Builder<BranchHourException>  $query
     * @return Builder<BranchHourException>
     */
    public function scopeUpcoming(Builder $query, ?Carbon $from = null): Builder
    {
        return $query->whereDate('date', '>=', ($from ?? Carbon::now())->toDateString())
            ->orderBy('date');
    }
}
