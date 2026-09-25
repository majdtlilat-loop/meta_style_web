<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use Carbon\CarbonImmutable;

/**
 * Which points are still usable, derived from the append-only history — first
 * in, first out, each credit keeping ITS OWN expiry.
 *
 * Every credit (an earning, an adjustment in, returned points) is a lot with the
 * `expires_at` it was written with. Walking the history oldest first:
 *
 *   before each row      lots whose own `expires_at` has passed are expired —
 *                        what they still held is forfeited
 *   a credit             opens a lot
 *   a debit              uses the OLDEST still-valid lots first (redemptions,
 *                        refund reversals, recoveries, adjustments out)
 *   an `expiry` row      records forfeited points; it uses no lot
 *
 * Then at `now` the same expiry is applied once more. What was forfeited but not
 * yet recorded is `due`: the next movement writes it as an `expiry` row, which
 * makes `due` zero again — idempotent by construction.
 *
 * The program's CURRENT expiry setting is never read here: points earned under
 * a 90-day rule keep 90 days after the program changes to 30
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21).
 */
final class PointsLots
{
    /**
     * @return array{available: int, due: int, lots: list<array{remaining: int, expires_at: CarbonImmutable|null}>}
     */
    public function state(LoyaltyAccount $account, CarbonImmutable $now): array
    {
        /** @var list<LoyaltyTransaction> $rows */
        $rows = LoyaltyTransaction::query()
            ->where('loyalty_account_id', $account->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'kind', 'direction', 'points', 'expires_at', 'occurred_at'])
            ->all();

        /** @var list<array{remaining: int, expires_at: CarbonImmutable|null}> $lots */
        $lots = [];
        $forfeited = 0;
        $recorded = 0;

        foreach ($rows as $row) {
            $at = CarbonImmutable::instance($row->occurred_at);
            $forfeited += self::expire($lots, $at);

            if ($row->direction === PointsDirection::In) {
                $lots[] = [
                    'remaining' => $row->points,
                    'expires_at' => $row->expires_at === null ? null : CarbonImmutable::instance($row->expires_at),
                ];

                continue;
            }

            if ($row->kind === PointsKind::Expiry) {
                $recorded += $row->points;

                continue;
            }

            self::consume($lots, $row->points);
        }

        $forfeited += self::expire($lots, $now);

        return [
            'available' => array_sum(array_column($lots, 'remaining')),
            'due' => max(0, $forfeited - $recorded),
            'lots' => $lots,
        ];
    }

    /**
     * The expiry points get back if a redemption of `$points` taken now is ever
     * returned: the LATEST expiry among the lots it uses — or none, if any of
     * them never expires. Returned points are not made to live longer than the
     * newest of what was spent.
     *
     * @param  list<array{remaining: int, expires_at: CarbonImmutable|null}>  $lots  from {@see state()}
     */
    public function restoreExpiry(array $lots, int $points): ?CarbonImmutable
    {
        $latest = null;

        foreach ($lots as $lot) {
            if ($points <= 0) {
                break;
            }

            if ($lot['remaining'] <= 0) {
                continue;
            }

            if ($lot['expires_at'] === null) {
                return null;
            }

            $latest = $latest === null || $lot['expires_at']->greaterThan($latest) ? $lot['expires_at'] : $latest;
            $points -= $lot['remaining'];
        }

        return $latest;
    }

    /**
     * @param  list<array{remaining: int, expires_at: CarbonImmutable|null}>  $lots
     */
    private static function expire(array &$lots, CarbonImmutable $at): int
    {
        $forfeited = 0;

        foreach ($lots as $index => $lot) {
            if ($lot['remaining'] > 0 && $lot['expires_at'] !== null && $lot['expires_at']->lessThanOrEqualTo($at)) {
                $forfeited += $lot['remaining'];
                $lots[$index]['remaining'] = 0;
            }
        }

        return $forfeited;
    }

    /**
     * @param  list<array{remaining: int, expires_at: CarbonImmutable|null}>  $lots
     */
    private static function consume(array &$lots, int $points): void
    {
        foreach ($lots as $index => $lot) {
            if ($points <= 0) {
                return;
            }

            $take = min($lot['remaining'], $points);
            $lots[$index]['remaining'] -= $take;
            $points -= $take;
        }
    }
}
