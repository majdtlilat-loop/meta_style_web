<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finds a customer's points account, creating it on first use, and LOCKS it.
 *
 *     INSERT IGNORE the account row      ← two first earnings at once cannot
 *                                           create two accounts
 *     SELECT ... FOR UPDATE              ← every movement serialises here
 *
 * The lock order is always: the sale (when there is one), then this row
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §24).
 */
final class LoyaltyAccounts
{
    public function lockFor(int $customerId): LoyaltyAccount
    {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('LoyaltyAccounts::lockFor() must run inside a transaction.');
        }

        $now = now()->utc();

        LoyaltyAccount::query()->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'balance' => 0,
            'lifetime_points' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var LoyaltyAccount $account */
        $account = LoyaltyAccount::query()->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();

        return $account;
    }

    /**
     * The account for reading — no lock, and no account created.
     */
    public function find(int $customerId): ?LoyaltyAccount
    {
        /** @var LoyaltyAccount|null $account */
        $account = LoyaltyAccount::query()->where('customer_id', $customerId)->first();

        return $account;
    }
}
