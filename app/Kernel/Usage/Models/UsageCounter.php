<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The authoritative running total for one resource in one period.
 *
 * THE ROW A QUOTA DECISION IS MADE AGAINST, which is why it lives in the
 * center's own database: the decision is a single conditional UPDATE against
 * this row, and a cross-database check would have a gap between the read and
 * the write (docs/26-USAGE-QUOTAS.md §5).
 *
 * `allowance_snapshot` is NULL for unlimited. `allowance_version` records which
 * control-plane override produced the snapshot, so reconciliation can tell an
 * unapplied change from an already-applied one (§8).
 *
 * @property string $resource
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int|null $allowance_snapshot
 * @property int|null $allowance_version
 * @property int $used
 * @property CarbonImmutable|null $last_activity_at
 */
final class UsageCounter extends Model
{
    use UsesTenantConnection;

    protected $table = 'usage_counters';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'allowance_snapshot' => 'integer',
            'allowance_version' => 'integer',
            'used' => 'integer',
            'last_activity_at' => 'immutable_datetime',
        ];
    }

    public function isUnlimited(): bool
    {
        return $this->allowance_snapshot === null;
    }

    /**
     * How much is left, or null when unlimited.
     *
     * Clamped at zero: `used` can legitimately exceed the allowance on a
     * METERED resource (nothing refuses those), and reporting "-2,000
     * remaining" on a dashboard would be a number nobody can act on.
     */
    public function remaining(): ?int
    {
        if ($this->allowance_snapshot === null) {
            return null;
        }

        return max(0, $this->allowance_snapshot - $this->used);
    }

    /**
     * Whole percent used, or null when unlimited.
     *
     * A percentage of unlimited does not exist — it is not 0 and not 100 — and
     * inventing either puts a wrong number on a manager's screen (§10).
     */
    public function percent(): ?int
    {
        $allowance = $this->allowance_snapshot;

        if ($allowance === null) {
            return null;
        }

        if ($allowance === 0) {
            // An allowance of zero is "none of this is included". Any use at
            // all is therefore fully spent; no use is not.
            return $this->used > 0 ? 100 : 0;
        }

        return (int) floor($this->used / $allowance * 100);
    }
}
