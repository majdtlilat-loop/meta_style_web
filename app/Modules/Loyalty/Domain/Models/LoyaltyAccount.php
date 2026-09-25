<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A customer's points account: the row every movement locks.
 *
 * `balance` and `lifetime_points` are written only by `LoyaltyLedger`, with the
 * history row that changes them, under this row's lock — a cache of the
 * history, never a number anybody edits (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §4).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int $balance
 * @property int $lifetime_points
 * @property int $unrecovered_points refund reversals not yet covered; the next earnings settle them first
 */
final class LoyaltyAccount extends Model
{
    use UsesTenantConnection;

    protected $table = 'loyalty_accounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'balance' => 'integer',
            'lifetime_points' => 'integer',
            'unrecovered_points' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $account): void {
            $account->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
