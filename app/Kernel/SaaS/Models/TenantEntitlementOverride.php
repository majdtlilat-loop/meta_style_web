<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Models;

use App\Kernel\SaaS\Enums\OverrideMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A per-tenant grant or revocation layered over the plan.
 *
 * @property string $tenant_id
 * @property string $entitlement
 * @property OverrideMode $mode
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 */
final class TenantEntitlementOverride extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_entitlement_overrides';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => OverrideMode::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Only overrides inside their validity window count.
     *
     * A time-boxed grant that silently outlives its window is how a support
     * courtesy becomes a permanent free feature.
     *
     * @param  Builder<TenantEntitlementOverride>  $query
     * @return Builder<TenantEntitlementOverride>
     */
    public function scopeActive(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query
            ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }
}
