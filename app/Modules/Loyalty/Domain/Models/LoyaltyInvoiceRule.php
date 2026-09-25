<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The spend rule one invoice earns under, frozen at its first qualifying
 * collection (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §4).
 *
 * @property int $id
 * @property string $invoice_uuid
 * @property int $loyalty_account_id
 * @property int $spend_points
 * @property int $spend_unit_minor
 * @property int $min_spend_minor
 * @property Carbon $created_at
 */
final class LoyaltyInvoiceRule extends Model
{
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_invoice_rules';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loyalty_account_id' => 'integer',
            'spend_points' => 'integer',
            'spend_unit_minor' => 'integer',
            'min_spend_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Points for `$netCollectedMinor` of money under this frozen rule.
     */
    public function target(int $netCollectedMinor): int
    {
        if ($this->spend_points < 1 || $this->spend_unit_minor < 1
            || $netCollectedMinor <= 0 || $netCollectedMinor < $this->min_spend_minor) {
            return 0;
        }

        return min(intdiv($netCollectedMinor, $this->spend_unit_minor) * $this->spend_points, 1_000_000_000);
    }
}
