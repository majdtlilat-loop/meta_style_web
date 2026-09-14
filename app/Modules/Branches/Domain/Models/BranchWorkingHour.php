<?php

declare(strict_types=1);

namespace App\Modules\Branches\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interval a branch is open.
 *
 * A DAY IS A LIST OF THESE, not a single open/close pair. Split shifts —
 * 09:00–13:00, closed for the afternoon, 16:00–22:00 — are the normal shape in
 * this market, so a schema that assumed one interval per day would be wrong for
 * most centers on day one.
 *
 * Times are wall-clock in the BRANCH's timezone, stored as plain TIME. "We open
 * at nine" does not shift with daylight saving or with where the server runs
 * (docs/10-API-FOUNDATION.md §8).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $day_of_week
 * @property string $opens_at
 * @property string $closes_at
 * @property int $sort_order
 */
final class BranchWorkingHour extends Model
{
    use UsesTenantConnection;

    protected $table = 'branch_working_hours';

    protected $guarded = [];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Does this interval run past midnight?
     *
     * A convention, not a column: `closes_at <= opens_at` says it already, and
     * a `spans_midnight` boolean would be a second source of truth that could
     * disagree with the times beside it. A barber open 20:00–02:00 is one row.
     */
    public function crossesMidnight(): bool
    {
        return $this->normalised($this->closes_at) <= $this->normalised($this->opens_at);
    }

    /**
     * Minutes from midnight, for comparison and for Booking later.
     */
    public function opensAtMinutes(): int
    {
        return $this->toMinutes($this->opens_at);
    }

    public function closesAtMinutes(): int
    {
        $minutes = $this->toMinutes($this->closes_at);

        // An interval that crosses midnight closes on the FOLLOWING day, so its
        // closing minute is past 1440. Returning 120 for "02:00" would make
        // every duration calculation negative.
        return $this->crossesMidnight() ? $minutes + 1440 : $minutes;
    }

    public function durationMinutes(): int
    {
        return $this->closesAtMinutes() - $this->opensAtMinutes();
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return ($hours * 60) + $minutes;
    }

    /** `09:00:00` and `09:00` must compare equal. */
    private function normalised(string $time): string
    {
        return mb_substr($time, 0, 5);
    }
}
