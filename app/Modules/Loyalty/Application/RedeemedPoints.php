<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Audit\Actor;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use App\Modules\Sales\Domain\Events\SaleBenefitReleased;
use App\Modules\Sales\Domain\Events\SaleDraftDiscarded;
use App\Modules\Sales\Domain\Events\SaleVoided;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;

/**
 * Gives redeemed points back — once.
 *
 * A redemption is returned when its discount is withdrawn from the draft, when
 * the draft is discarded, and when the finalized sale is voided: the customer
 * did not, in the end, get anything for those points. The return is an explicit
 * `reversal` row keyed on the redemption, so a second return — a retried void,
 * a withdraw racing a discard — writes nothing.
 *
 * Never blocked by a downgrade: giving back what was taken is not new loyalty
 * activity (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§8, 22). Runs inside the
 * sale's own transaction, after the sale lock: the order every benefit uses.
 */
final class RedeemedPoints
{
    public function __construct(
        private readonly LoyaltyLedger $ledger,
        private readonly LoyaltyAudit $audit,
    ) {}

    public function giveBack(string $redemptionUuid, string $reason, Actor $actor): void
    {
        /** @var LoyaltyTransaction|null $redeem */
        $redeem = LoyaltyTransaction::query()
            ->where('source_type', PointsSource::Benefit->value)
            ->where('source_uuid', $redemptionUuid)
            ->where('kind', PointsKind::Redeem->value)
            ->first();

        if (! $redeem instanceof LoyaltyTransaction) {
            return;
        }

        /** @var LoyaltyAccount $account */
        $account = LoyaltyAccount::query()->whereKey($redeem->loyalty_account_id)->lockForUpdate()->firstOrFail();

        $alreadyReturned = LoyaltyTransaction::query()
            ->where('source_type', PointsSource::Benefit->value)
            ->where('source_uuid', $redemptionUuid)
            ->where('kind', PointsKind::Reversal->value)
            ->exists();

        if ($alreadyReturned) {
            return;
        }

        $returned = $this->ledger->append(
            $account,
            PointsKind::Reversal,
            PointsDirection::In,
            $redeem->points,
            PointsSource::Benefit,
            $redemptionUuid,
            CarbonImmutable::now()->utc(),
            contextUuid: $redeem->context_uuid,
            reason: $reason,
            actor: $actor,
            // The expiry snapshotted when they were spent — the latest of the
            // credits they came from. Returned points never live longer.
            expiresAt: $redeem->expires_at === null ? null : CarbonImmutable::instance($redeem->expires_at),
        );

        $this->audit->record('loyalty.redemption_returned', $actor, $account, $account->uuid,
            after: ['points' => $returned->points, 'balance' => $account->balance],
            meta: ['redemption' => $redemptionUuid, 'sale' => $redeem->context_uuid],
            reason: $reason,
        );
    }

    public function handleSaleVoided(SaleVoided $event): void
    {
        $saleUuid = Sale::query()->whereKey($event->saleId)->value('uuid');

        if (is_string($saleUuid)) {
            $this->giveBackForSale($saleUuid, 'Sale voided');
        }
    }

    public function handleSaleDraftDiscarded(SaleDraftDiscarded $event): void
    {
        $this->giveBackForSale($event->saleUuid, 'Draft discarded');
    }

    /**
     * A hold on a draft nobody came back to expired: the points are the
     * customer's again (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7).
     */
    public function handleBenefitReleased(SaleBenefitReleased $event): void
    {
        if ($event->sourceType === RedeemPoints::SOURCE) {
            $this->giveBack($event->sourceReference, 'The draft holding them was abandoned', Actor::system('loyalty'));
        }
    }

    private function giveBackForSale(string $saleUuid, string $reason): void
    {
        $redemptions = LoyaltyTransaction::query()
            ->where('context_uuid', $saleUuid)
            ->where('source_type', PointsSource::Benefit->value)
            ->where('kind', PointsKind::Redeem->value)
            ->pluck('source_uuid')
            ->all();

        foreach ($redemptions as $redemptionUuid) {
            $this->giveBack((string) $redemptionUuid, $reason, Actor::system('loyalty'));
        }
    }
}
