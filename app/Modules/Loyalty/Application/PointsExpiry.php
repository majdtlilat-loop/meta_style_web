<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Writes off points that aged out — LAZILY, under the account lock, before any
 * movement reads the balance.
 *
 * Which points aged out is `PointsLots`' answer, from each credit's own expiry
 * snapshot. This only records it, as an `expiry` row, so that afterwards the
 * balance IS what can be used: an expired point can never be redeemed, and no
 * scheduler is needed. Running it twice writes nothing the second time
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21).
 */
final class PointsExpiry
{
    public function __construct(
        private readonly LoyaltyLedger $ledger,
        private readonly PointsLots $lots,
    ) {}

    /**
     * Points that aged out and are not yet written off. Reading only.
     */
    public function due(LoyaltyAccount $account, CarbonImmutable $now): int
    {
        return min($this->lots->state($account, $now->utc())['due'], $account->balance);
    }

    /**
     * Writes off what aged out, on the LOCKED account. Returns the points
     * expired now.
     */
    public function apply(LoyaltyAccount $locked, CarbonImmutable $now): int
    {
        $due = $this->due($locked, $now);

        if ($due > 0) {
            $this->ledger->append(
                $locked,
                PointsKind::Expiry,
                PointsDirection::Out,
                $due,
                PointsSource::Expiry,
                (string) Str::uuid(),
                $now->utc(),
                reason: 'Points reached their expiry',
            );
        }

        return $due;
    }
}
