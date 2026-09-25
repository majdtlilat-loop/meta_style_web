<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A plan change agreed for a later date.
 *
 * @property int $subscription_id
 * @property int|null $target_plan_id
 * @property string $status
 * @property Carbon|null $effective_at
 */
final class SubscriptionScheduledChange extends Model
{
    protected $connection = 'control';

    protected $table = 'subscription_scheduled_changes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $change): void {
            $change->uuid ??= (string) Str::uuid();
        });
    }
}
