<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use App\Kernel\Audit\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing that happened to a subscription: a plan or billing-cycle change,
 * a trial extension, an activation, a suspension. Append-only — a correction
 * is a new row, never an edit — so "what was this center paying in March"
 * always has an answer.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $tenant_id
 * @property string $event
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property string|null $from_cycle
 * @property string|null $to_cycle
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $price_minor
 * @property string|null $currency
 * @property array<string, string>|null $plan_name
 * @property array<string, mixed>|null $details
 * @property string|null $reason
 * @property string|null $actor_label
 * @property Carbon $occurred_at
 */
final class SubscriptionHistory extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'control';

    protected $table = 'subscription_history';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'plan_name' => 'array',
            'details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $details
     */
    public static function record(Subscription $subscription, string $event, Actor $actor, ?string $reason, array $before = [], array $details = []): self
    {
        /** @var self $row */
        $row = self::query()->create([
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'event' => $event,
            'from_plan_id' => $before['plan_id'] ?? null,
            'to_plan_id' => $subscription->plan_id,
            'from_cycle' => $before['cycle'] ?? null,
            'to_cycle' => $subscription->billing_period_snapshot,
            'from_status' => $before['status'] ?? null,
            'to_status' => $subscription->status->value,
            'price_minor' => $subscription->price_minor_snapshot,
            'currency' => $subscription->currency_snapshot,
            'plan_name' => $subscription->plan_name_snapshot,
            'details' => $details === [] ? null : $details,
            'reason' => $reason,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'occurred_at' => now(),
        ]);

        return $row;
    }
}
