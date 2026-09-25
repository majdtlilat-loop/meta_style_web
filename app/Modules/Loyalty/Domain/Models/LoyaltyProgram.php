<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The center's loyalty rules. One row; zero switches a rule off
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * @property int $id
 * @property int $spend_points
 * @property int $spend_unit_minor
 * @property int $min_spend_minor
 * @property int $visit_points
 * @property int $point_value_minor
 * @property int $min_redeem_points
 * @property int|null $expiry_days
 * @property string|null $updated_by_id
 * @property string|null $updated_by_label
 */
final class LoyaltyProgram extends Model
{
    use UsesTenantConnection;

    protected $table = 'loyalty_programs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spend_points' => 'integer',
            'spend_unit_minor' => 'integer',
            'min_spend_minor' => 'integer',
            'visit_points' => 'integer',
            'point_value_minor' => 'integer',
            'min_redeem_points' => 'integer',
            'expiry_days' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        /** @var self|null $program */
        $program = self::query()->where('singleton', 1)->first();

        return $program;
    }

    public function earnsOnSpend(): bool
    {
        return $this->spend_points > 0 && $this->spend_unit_minor > 0;
    }

    public function earnsOnVisits(): bool
    {
        return $this->visit_points > 0;
    }

    public function allowsRedemption(): bool
    {
        return $this->point_value_minor > 0;
    }

    /**
     * The expiry to SNAPSHOT onto a credit written NOW under today's rules, or
     * null when points do not expire — for points given by hand, which happen
     * now by definition. Earning from money or a visit uses the rule VERSION in
     * force when that event happened instead
     * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§6, 21).
     */
    public function creditExpiry(CarbonImmutable $at): ?CarbonImmutable
    {
        return $this->expiry_days === null || $this->expiry_days < 1 ? null : $at->utc()->addDays($this->expiry_days);
    }
}
