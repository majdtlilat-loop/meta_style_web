<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One tenant's commercial standing.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $plan_id
 * @property int|null $price_minor_snapshot
 * @property string|null $currency_snapshot
 * @property string|null $billing_period_snapshot
 * @property array<string, string>|null $plan_name_snapshot
 * @property SubscriptionStatus $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $grace_ends_at
 */
final class Subscription extends Model
{
    protected $connection = 'control';

    protected $table = 'subscriptions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price_minor_snapshot' => 'integer',
            'plan_name_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $subscription): void {
            $subscription->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<TenantModel, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id');
    }

    /**
     * Has the trial run out?
     *
     * Computed from the stored timestamp rather than trusting `status` alone,
     * so an expired trial is refused the moment it lapses — even if the
     * scheduled sweep has not run yet. Expiry must be enforced server-side and
     * never by the UI hiding something.
     */
    public function trialHasExpired(?Carbon $now = null): bool
    {
        if ($this->status !== SubscriptionStatus::Trialing || $this->trial_ends_at === null) {
            return false;
        }

        return $this->trial_ends_at->isBefore($now ?? Carbon::now());
    }

    /**
     * The status as it should be treated right now.
     *
     * A lapsed trial reads as Expired even before the sweep updates the row.
     */
    public function effectiveStatus(?Carbon $now = null): SubscriptionStatus
    {
        return $this->trialHasExpired($now) ? SubscriptionStatus::Expired : $this->status;
    }

    public function grantsAccess(?Carbon $now = null): bool
    {
        return $this->effectiveStatus($now)->grantsAccess();
    }

    /**
     * Whole days left in the trial, floored at zero.
     *
     * The reminder jobs in Phase 14 read this; nothing sends anything yet.
     */
    public function trialDaysRemaining(?Carbon $now = null): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        $now ??= Carbon::now();

        return max(0, (int) $now->diffInDays($this->trial_ends_at, false));
    }

    /**
     * Days of trial left as a person counts them: calendar days from today to
     * the day the trial ends, whatever the time of day on either side. A new
     * 14-day trial reads "14 days", not "13".
     */
    public function trialDaysLeft(?Carbon $now = null): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        $today = ($now ?? Carbon::now())->copy()->startOfDay();

        return max(0, (int) $today->diffInDays($this->trial_ends_at->copy()->startOfDay(), false));
    }
}
