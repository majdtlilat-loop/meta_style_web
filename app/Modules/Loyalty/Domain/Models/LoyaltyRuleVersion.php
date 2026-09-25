<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Database\Concerns\AppendOnlyHistory;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One version of the center's EARNING rules, with the instant it took effect.
 * Written once, never changed (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * @property int $id
 * @property string $uuid
 * @property Carbon $effective_from
 * @property int $spend_points
 * @property int $spend_unit_minor
 * @property int $min_spend_minor
 * @property int $visit_points
 * @property int|null $expiry_days
 * @property string|null $changed_by_id
 * @property string|null $changed_by_label
 * @property Carbon $created_at
 */
final class LoyaltyRuleVersion extends Model
{
    use AppendOnlyHistory;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_rule_versions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'spend_points' => 'integer',
            'spend_unit_minor' => 'integer',
            'min_spend_minor' => 'integer',
            'visit_points' => 'integer',
            'expiry_days' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $version): void {
            $version->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * The version effective at `$when` — the latest one that had taken effect
     * by then. Null before the center ever set its rules.
     */
    public static function effectiveAt(CarbonInterface $when): ?self
    {
        /** @var self|null $version */
        $version = self::query()
            ->where('effective_from', '<=', $when)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $version;
    }

    public function earnsOnSpend(): bool
    {
        return $this->spend_points > 0 && $this->spend_unit_minor > 0;
    }

    public function earnsOnVisits(): bool
    {
        return $this->visit_points > 0;
    }

    /**
     * Points for `$netMinor` of money under this version.
     */
    public function target(int $netMinor): int
    {
        if (! $this->earnsOnSpend() || $netMinor <= 0 || $netMinor < $this->min_spend_minor) {
            return 0;
        }

        return min(intdiv($netMinor, $this->spend_unit_minor) * $this->spend_points, 1_000_000_000);
    }

    /**
     * When points earned at `$at` under THIS version expire, or null when they
     * never do. Counted from the event's own time, so a repair years later
     * still produces the date the customer should have had (§21).
     */
    public function expiryFor(CarbonInterface $at): ?CarbonInterface
    {
        return $this->expiry_days === null || $this->expiry_days < 1 ? null : $at->toImmutable()->addDays($this->expiry_days);
    }

    /**
     * @return array{spend_points: int, spend_unit_minor: int, min_spend_minor: int}
     */
    public function spendRule(): array
    {
        return [
            'spend_points' => $this->spend_points,
            'spend_unit_minor' => $this->spend_unit_minor,
            'min_spend_minor' => $this->min_spend_minor,
        ];
    }
}
