<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Domain\Enums\CustomerPackageStatus;
use App\Modules\Packages\Domain\Enums\PackageMovement;
use App\Modules\Packages\Domain\Enums\PackageSource;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\CustomerPackageItem;
use App\Modules\Packages\Domain\Models\PackageTransaction;
use App\Modules\Sales\Domain\Events\SaleBenefitReleased;
use App\Modules\Sales\Domain\Events\SaleDraftDiscarded;
use App\Modules\Sales\Domain\Events\SaleVoided;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;

/**
 * Gives package sessions back, and ends packages a voided sale sold.
 *
 *   a redemption withdrawn, its draft discarded, its sale voided
 *        → a `reversal` of exactly that redemption, once
 *   the sale that SOLD a package is voided
 *        → that package is cancelled; what was left is forfeited as a
 *          `cancellation` — its history stays
 *
 * Inside the sale's own transaction, after its lock. Never blocked by a
 * downgrade (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§8, 22).
 */
final class RedeemedSessions
{
    public function __construct(
        private readonly PackageLedger $ledger,
        private readonly PackagesAudit $audit,
    ) {}

    public function giveBack(string $redemptionUuid, string $reason, Actor $actor): void
    {
        /** @var PackageTransaction|null $redemption */
        $redemption = PackageTransaction::query()
            ->where('source_type', PackageSource::Benefit->value)
            ->where('source_uuid', $redemptionUuid)
            ->where('kind', PackageMovement::Redemption->value)
            ->first();

        if (! $redemption instanceof PackageTransaction) {
            return;
        }

        /** @var CustomerPackage $package */
        $package = CustomerPackage::query()->whereKey($redemption->customer_package_id)->lockForUpdate()->firstOrFail();
        /** @var CustomerPackageItem $item */
        $item = CustomerPackageItem::query()->findOrFail($redemption->customer_package_item_id);

        $returned = PackageTransaction::query()
            ->where('source_type', PackageSource::Benefit->value)
            ->where('source_uuid', $redemptionUuid)
            ->where('kind', PackageMovement::Reversal->value)
            ->exists();

        if ($returned) {
            return;
        }

        $this->ledger->append($package, $item, PackageMovement::Reversal, $redemption->quantity,
            PackageSource::Benefit, $redemptionUuid, CarbonImmutable::now()->utc(),
            saleUuid: $redemption->sale_uuid, journeyStageId: $redemption->journey_stage_id, reason: $reason, actor: $actor);

        $this->audit->record('package.redemption_returned', $actor, $package, $package->uuid,
            after: ['quantity' => $redemption->quantity],
            meta: ['redemption' => $redemptionUuid, 'sale' => $redemption->sale_uuid],
            reason: $reason,
        );
    }

    /**
     * Cancels a LOCKED package: status, who and why, and a cancellation of every
     * session still left. Idempotent.
     */
    public function cancel(CustomerPackage $locked, string $reason, Actor $actor, CarbonImmutable $at): void
    {
        if ($locked->status === CustomerPackageStatus::Cancelled) {
            return;
        }

        $left = $this->ledger->left($locked);

        foreach ($locked->items()->get() as $item) {
            $remaining = $left[(int) $item->getKey()] ?? 0;

            if ($remaining > 0) {
                $this->ledger->append($locked, $item, PackageMovement::Cancellation, $remaining,
                    PackageSource::Cancellation, $item->uuid, $at, reason: $reason, actor: $actor);
            }
        }

        $locked->forceFill([
            'status' => CustomerPackageStatus::Cancelled,
            'cancelled_at' => $at,
            'cancelled_by_id' => $actor->id,
            'cancelled_by_label' => $actor->label,
            'cancel_reason' => mb_substr($reason, 0, 190),
        ])->save();

        $this->audit->record('package.cancelled', $actor, $locked, $locked->uuid,
            after: ['status' => CustomerPackageStatus::Cancelled->value, 'forfeited' => array_sum(array_map(static fn (int $n): int => max(0, $n), $left))],
            before: ['status' => CustomerPackageStatus::Active->value],
            reason: $reason,
            severity: AuditSeverity::Warning,
        );
    }

    public function handleSaleVoided(SaleVoided $event): void
    {
        /** @var Sale|null $sale */
        $sale = Sale::query()->find($event->saleId);

        if (! $sale instanceof Sale) {
            return;
        }

        $actor = Actor::system('packages');

        $this->giveBackForSale($sale->uuid, 'Sale voided', $actor);

        // What the voided sale SOLD ends with it.
        foreach (CustomerPackage::query()->where('sale_id', $sale->getKey())->pluck('id') as $packageId) {
            /** @var CustomerPackage $package */
            $package = CustomerPackage::query()->whereKey($packageId)->lockForUpdate()->firstOrFail();

            $this->cancel($package, 'The sale that sold it was voided', $actor, CarbonImmutable::now()->utc());
        }
    }

    public function handleSaleDraftDiscarded(SaleDraftDiscarded $event): void
    {
        $this->giveBackForSale($event->saleUuid, 'Draft discarded', Actor::system('packages'));
    }

    /**
     * A hold on a draft nobody came back to expired: the sessions are the
     * customer's again (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7).
     */
    public function handleBenefitReleased(SaleBenefitReleased $event): void
    {
        if ($event->sourceType === ApplyPackage::SOURCE) {
            $this->giveBack($event->sourceReference, 'The draft holding them was abandoned', Actor::system('packages'));
        }
    }

    private function giveBackForSale(string $saleUuid, string $reason, Actor $actor): void
    {
        $redemptions = PackageTransaction::query()
            ->where('sale_uuid', $saleUuid)
            ->where('source_type', PackageSource::Benefit->value)
            ->where('kind', PackageMovement::Redemption->value)
            ->pluck('source_uuid')
            ->all();

        foreach ($redemptions as $redemptionUuid) {
            $this->giveBack((string) $redemptionUuid, $reason, $actor);
        }
    }
}
