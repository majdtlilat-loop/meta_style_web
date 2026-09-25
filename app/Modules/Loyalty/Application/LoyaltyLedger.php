<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Audit\Actor;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The only writer of `loyalty_transactions` — and of the account figures it
 * caches.
 *
 *   history row         appended, never edited
 *   balance             += points in, −= points out. Never below zero.
 *   lifetime_points     QUALIFYING points, what tiers are measured on:
 *                         + every earning
 *                         − the FULL earning a refund reversed — the part the
 *                           balance covered AND the unrecovered part
 *                       Redemptions, expiry and recoveries do not change it: a
 *                       tier is about what was earned and kept, not what was
 *                       spent.
 *   unrecovered_points  + the part of a refund reversal the balance could not
 *                         cover, recorded on that reversal
 *                       − settled by the next EARNINGS: each earning writes an
 *                         explicit `recovery` row, of up to its own points,
 *                         before any of it is available. The customer never
 *                         sees a negative balance, and the center never
 *                         silently loses what a refund took back
 *                         (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §8).
 *
 * Every write happens on the LOCKED account row, in the caller's transaction,
 * so `balance == Σ in − Σ out` always holds (a test proves it). Idempotent per
 * (source, source uuid, kind): the same payment, refund, visit or redemption
 * returns the row it already wrote.
 */
final class LoyaltyLedger
{
    public const MAX_POINTS = 1_000_000_000;

    public function append(
        LoyaltyAccount $locked,
        PointsKind $kind,
        PointsDirection $direction,
        int $points,
        PointsSource $source,
        string $sourceUuid,
        CarbonInterface $occurredAt,
        ?string $contextUuid = null,
        ?string $reason = null,
        ?Actor $actor = null,
        int $unrecovered = 0,
        ?CarbonInterface $expiresAt = null,
    ): LoyaltyTransaction {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('LoyaltyLedger::append() must run inside the transaction that moved the points.');
        }

        if ($points < 0 || $unrecovered < 0 || $points > self::MAX_POINTS || ($points === 0 && $unrecovered === 0)) {
            throw new LogicException('A points movement moves a positive number of points.');
        }

        if ($unrecovered > 0 && ! ($kind === PointsKind::Reversal && $source === PointsSource::Refund)) {
            throw new LogicException('Only a refund reversal can leave points unrecovered.');
        }

        /** @var LoyaltyTransaction|null $existing */
        $existing = LoyaltyTransaction::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $sourceUuid)
            ->where('kind', $kind->value)
            ->first();

        if ($existing instanceof LoyaltyTransaction) {
            return $existing;
        }

        $transaction = $this->write($locked, $kind, $direction, $points, $source, $sourceUuid, $occurredAt,
            $contextUuid, $reason, $actor, $unrecovered, $expiresAt);

        $lifetime = $locked->lifetime_points;
        $outstanding = $locked->unrecovered_points;

        if ($kind === PointsKind::Earn) {
            $lifetime += $points;
        } elseif ($kind === PointsKind::Reversal && $source === PointsSource::Refund) {
            $lifetime = max(0, $lifetime - $points - $unrecovered);
            $outstanding += $unrecovered;
        }

        $locked->forceFill([
            'lifetime_points' => min($lifetime, self::MAX_POINTS * 4),
            'unrecovered_points' => min($outstanding, self::MAX_POINTS * 4),
        ])->save();

        // An earning settles what earlier refunds could not take back, first.
        if ($kind === PointsKind::Earn && $locked->unrecovered_points > 0) {
            $settle = min($points, $locked->unrecovered_points);

            $this->write($locked, PointsKind::Recovery, PointsDirection::Out, $settle,
                PointsSource::Recovery, $transaction->uuid, $occurredAt, $contextUuid,
                'Settles points an earlier refund could not take back', $actor, 0, null);

            $locked->forceFill(['unrecovered_points' => $locked->unrecovered_points - $settle])->save();
        }

        return $transaction;
    }

    /**
     * What one invoice has earned so far, net of its refund reversals —
     * counting a reversal's unrecovered part too, since that was taken back
     * from what the invoice earned even when the balance could not cover it.
     */
    public function earnedOn(LoyaltyAccount $locked, string $invoiceUuid): int
    {
        /** @var array<string, int> $sums */
        $sums = LoyaltyTransaction::query()
            ->where('loyalty_account_id', $locked->getKey())
            ->where('context_uuid', $invoiceUuid)
            ->whereIn('source_type', [PointsSource::Payment->value, PointsSource::Refund->value])
            ->selectRaw('kind, SUM(points + unrecovered_points) AS total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return max(0, ($sums[PointsKind::Earn->value] ?? 0) - ($sums[PointsKind::Reversal->value] ?? 0));
    }

    private function write(
        LoyaltyAccount $locked,
        PointsKind $kind,
        PointsDirection $direction,
        int $points,
        PointsSource $source,
        string $sourceUuid,
        CarbonInterface $occurredAt,
        ?string $contextUuid,
        ?string $reason,
        ?Actor $actor,
        int $unrecovered,
        ?CarbonInterface $expiresAt,
    ): LoyaltyTransaction {
        $balance = $direction === PointsDirection::In ? $locked->balance + $points : $locked->balance - $points;

        if ($balance < 0) {
            // A caller that could take the balance negative has a bug. Points
            // are never a debt the customer sees (§8).
            throw new LogicException('A points movement cannot take a balance below zero.');
        }

        /** @var LoyaltyTransaction $transaction */
        $transaction = LoyaltyTransaction::query()->create([
            'loyalty_account_id' => $locked->getKey(),
            'kind' => $kind,
            'direction' => $direction,
            'points' => $points,
            'unrecovered_points' => $unrecovered,
            'expires_at' => $expiresAt,
            'source_type' => $source,
            'source_uuid' => $sourceUuid,
            'context_uuid' => $contextUuid,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 190),
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->label,
            'occurred_at' => $occurredAt,
            'created_at' => now()->utc(),
        ]);

        $locked->forceFill(['balance' => $balance])->save();

        return $transaction;
    }
}
