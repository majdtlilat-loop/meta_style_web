<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that was used, once.
 *
 * APPEND ONLY. Nothing here is updated or deleted except by retention, because
 * this is the evidence behind a number a center is billed against — and a
 * corrected total that cannot be traced back to what changed is not a total
 * anybody can defend (docs/26-USAGE-QUOTAS.md §5).
 *
 * Idempotency is the `(resource, source_type, source_uuid)` unique index and
 * `insertOrIgnore`, never a query that asks whether this was already counted.
 * A check-then-insert has a race in exactly the case that matters: the same
 * webhook delivered twice, a second apart, by two workers.
 *
 * @property string $resource
 * @property int $quantity
 * @property string $source_type
 * @property string $source_uuid
 * @property string|null $provider
 * @property string|null $model
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $period_start
 */
final class UsageEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'usage_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'period_start' => 'immutable_datetime',
        ];
    }
}
